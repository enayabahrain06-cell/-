<?php

namespace App\Services\Messaging;

use App\Enums\AttendanceStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\AttendanceConfirmation;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\WeekDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Automatic WhatsApp attendance messaging (section 23): the two pre-lesson reminders, absence and
 * repeated-absence notices, location change, cancellation and replies to inbound messages.
 *
 * Rules applied here for every message:
 * - recipients: the guardian always; a copy to the student's own phone when they are 12+ (setting);
 * - locale: the recipient's own (guardian / student user locale, else the student's locale);
 * - quiet hours / quiet days: anything due inside them is scheduled for the next allowed time;
 * - de-duplication: one template per recipient per session (message_logs.dedupe_key, unique);
 * - opt-out and invalid numbers: checked when the message is created (immediate) or falls due (planned).
 */
class AttendanceMessenger
{
    /** @var array<int, string> next session date per session id (per request) */
    private array $nextDates = [];

    public function __construct(
        private MessageService $messages,
        private MessagingRules $rules,
        private QuietHours $quiet,
        private TemplateRenderer $renderer,
        private DeliveryGuard $guard,
    ) {}

    // ------------------------------------------------------------------ helpers

    public function startsAt(LessonSession $session): Carbon
    {
        return Carbon::parse($session->session_date->toDateString().' '.WeekDays::time((string) $session->start_time), $this->rules->timezone())->utc();
    }

    /** The authority's local date today (sessions are stored as local dates). */
    public function localToday(): string
    {
        return now()->setTimezone($this->rules->timezone())->toDateString();
    }

    /**
     * @return list<array{phone: string, type: RecipientType, user: ?User, locale: string, guardian_name: string}>
     */
    public function recipients(Student $student, ?CarbonInterface $on = null, bool $includeStudent = true): array
    {
        $out = [];
        $guardian = $student->guardian;
        $studentLocale = $student->locale?->value ?? 'ar';
        $guardianName = (string) ($student->guardian_name ?: $guardian?->name ?? '');

        $gPhone = PhoneNumber::normalize($student->guardian_phone ?: $guardian?->phone);
        if ($gPhone) {
            $out[$gPhone] = [
                'phone' => $gPhone,
                'type' => RecipientType::Guardian,
                'user' => $guardian,
                'locale' => $guardian?->locale?->value ?? $studentLocale,
                'guardian_name' => $guardianName,
            ];
        }

        if ($includeStudent) {
            $own = $student->user;
            $sPhone = PhoneNumber::normalize($student->student_phone ?: $own?->phone);
            $age = $student->birth_date ? (int) floor($student->birth_date->diffInYears($on ?? now(), true)) : 0;
            if ($sPhone && ! isset($out[$sPhone]) && $age >= $this->rules->int('messaging.student_copy_min_age')) {
                $out[$sPhone] = [
                    'phone' => $sPhone,
                    'type' => RecipientType::Student,
                    'user' => $own,
                    'locale' => $own?->locale?->value ?? $studentLocale,
                    'guardian_name' => $guardianName,
                ];
            }
        }

        return array_values($out);
    }

    public function formatDate(CarbonInterface $date, string $locale): string
    {
        return Carbon::instance($date)->locale($locale === 'en' ? 'en' : 'ar')->isoFormat('dddd D/M/YYYY');
    }

    public function varsFor(LessonSession $session, Student $student, array $recipient, ?LessonStudent $enrolment = null): array
    {
        $session->loadMissing(['lesson.teacher', 'lesson.location', 'location']);
        $locale = $recipient['locale'];
        $location = $session->location ?? $session->lesson?->location;
        $enrolment ??= LessonStudent::where('lesson_id', $session->lesson_id)->where('student_id', $student->id)
            ->where('status', LessonStudentStatus::Active->value)->first();

        return [
            'name' => $student->full_name,
            'guardian_name' => $recipient['guardian_name'],
            'lesson' => $session->lesson?->name ?? '',
            'teacher' => $session->lesson?->teacher?->name ?? '',
            'date' => $this->formatDate($session->session_date, $locale),
            'time' => substr(WeekDays::time((string) $session->start_time), 0, 5),
            'location' => $location?->name ?? '',
            'map_link' => $location?->map_link ?? '',
            'assignment' => $enrolment?->current_memorization ?: '—',
            'next_date' => $this->nextDate($session, $locale),
            'supervisor_phone' => $this->rules->supervisorPhone(),
        ];
    }

    private function nextDate(LessonSession $session, string $locale): string
    {
        $key = $session->id.':'.$locale;
        if (! array_key_exists($key, $this->nextDates)) {
            $next = LessonSession::where('lesson_id', $session->lesson_id)
                ->where('session_date', '>', $session->session_date->toDateString())
                ->where('status', '!=', SessionStatus::Cancelled->value)
                ->orderBy('session_date')->first();
            $this->nextDates[$key] = $next ? $this->formatDate($next->session_date, $locale) : '—';
        }

        return $this->nextDates[$key];
    }

    /** Reminder keys carry the start date and time, so a rescheduled session gets fresh reminders. */
    public function dedupeKey(LessonSession $session, MessageType $type, string $phone, bool $withStart = false, ?int $studentId = null): string
    {
        $key = "s{$session->id}:{$type->value}:{$phone}".($studentId ? ":st{$studentId}" : "");

        return $withStart ? $key.':'.$session->session_date->toDateString().'T'.substr(WeekDays::time((string) $session->start_time), 0, 5) : $key;
    }

    private function sendAt(?CarbonInterface $at = null): Carbon
    {
        return $this->quiet->nextAllowed($at ?? now());
    }

    /** @return list<LessonStudent> active enrolments with their student */
    private function enrolled(LessonSession $session)
    {
        return LessonStudent::with(['student.guardian', 'student.user'])
            ->where('lesson_id', $session->lesson_id)
            ->where('status', LessonStudentStatus::Active->value)
            ->get()
            ->filter(fn (LessonStudent $ls) => $ls->student !== null)
            ->values();
    }

    // ------------------------------------------------------------------ reminders

    /**
     * Plan (status "scheduled") the two reminders for one session. Idempotent: planned rows are
     * de-duplicated. A first reminder whose time has passed is sent now; a second one whose time has
     * passed is dropped (so a late plan never sends both at once).
     *
     * @return int messages planned or queued by this call
     */
    public function planReminders(LessonSession $session): int
    {
        if ($session->status !== SessionStatus::Scheduled) {
            return 0;
        }
        $now = now();
        $starts = $this->startsAt($session);
        $lessonRule = $this->rules->forLesson($session->lesson_id);
        if ($starts->lte($now) || ! $lessonRule['reminders_enabled']) {
            return 0;
        }

        $steps = [];
        if ($this->rules->bool('messaging.reminder_1_enabled')) {
            $steps[] = [MessageType::AttendanceReminderLong, $this->rules->int('messaging.reminder_1_minutes')];
        }
        if ($this->rules->bool('messaging.reminder_2_enabled') && $lessonRule['second_reminder_enabled']) {
            $steps[] = [MessageType::AttendanceReminderShort, $this->rules->int('messaging.reminder_2_minutes')];
        }

        $excused = Attendance::where('lesson_session_id', $session->id)->where('status', AttendanceStatus::Excused->value)->pluck('student_id')->all();
        $confirmed = AttendanceConfirmation::where('lesson_session_id', $session->id)->pluck('student_id')->all();
        $enrolled = $this->enrolled($session);
        $planned = 0;

        foreach ($steps as [$type, $minutes]) {
            $due = $starts->copy()->subMinutes($minutes);
            if ($type === MessageType::AttendanceReminderShort && $due->lte($now)) {
                continue;
            }
            $at = $this->sendAt($due->max($now));
            if ($at->gte($starts)) {
                continue; // quiet hours reach past the start: nothing useful to send
            }

            foreach ($enrolled as $ls) {
                if (in_array($ls->student_id, $excused, true) || ($type === MessageType::AttendanceReminderShort && in_array($ls->student_id, $confirmed, true))) {
                    continue;
                }
                foreach ($this->recipients($ls->student, $session->session_date) as $r) {
                    $key = $this->dedupeKey($session, $type, $r["phone"], true, $ls->student_id);
                    if (MessageLog::where('dedupe_key', $key)->exists()) {
                        continue;
                    }
                    $log = $this->messages->send(
                        phone: $r['phone'], type: $type, vars: $this->varsFor($session, $ls->student, $r, $ls), locale: $r['locale'],
                        student: $ls->student, user: $r['user'], recipientType: $r['type'],
                        session: $session, sendAt: $at, dedupeKey: $key,
                    );
                    if ($log) {
                        $planned++;
                        if ($log->status === MessageStatus::Queued && $type === MessageType::AttendanceReminderLong && ! $session->reminder_sent_at) {
                            $session->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                        }
                    }
                }
            }
        }

        return $planned;
    }

    /**
     * Withdraw messages of a session that have not gone out yet (scheduled, or queued and waiting
     * for their slot). Their de-duplication key is released so the session can be planned again.
     *
     * @param  list<string>|null  $templates  limit to these template keys
     */
    public function cancelPending(LessonSession|int $session, string $reason, ?array $templates = null): int
    {
        $id = $session instanceof LessonSession ? $session->id : $session;

        return MessageLog::where('lesson_session_id', $id)
            ->whereIn('status', [MessageStatus::Scheduled->value, MessageStatus::Queued->value])
            ->when($templates, fn ($q) => $q->whereIn('template_key', $templates))
            ->update(['status' => MessageStatus::Cancelled->value, 'dedupe_key' => null, 'error' => $reason, 'updated_at' => now()]);
    }

    public function cancelReminders(LessonSession|int $session, string $reason): int
    {
        return $this->cancelPending($session, $reason, [MessageType::AttendanceReminderLong->value, MessageType::AttendanceReminderShort->value]);
    }

    /** Session date or start time changed: drop the old reminders and plan new ones. */
    public function reschedule(LessonSession $session): int
    {
        $this->cancelReminders($session, 'session_time_changed');

        return $this->planReminders($session);
    }

    /** Why a planned message should not go out any more, or null when it still should. */
    public function skipReason(MessageLog $log): ?string
    {
        if (! $log->type?->isReminder()) {
            return null;
        }
        $session = $log->session;
        if (! $session) {
            return 'session_missing';
        }
        if ($session->status !== SessionStatus::Scheduled) {
            return 'session_'.$session->status->value;
        }
        if (now()->gte($this->startsAt($session))) {
            return 'session_started';
        }
        $lessonRule = $this->rules->forLesson($session->lesson_id);
        if (! $lessonRule['reminders_enabled']) {
            return 'circle_disabled';
        }
        if ($log->type === MessageType::AttendanceReminderLong && ! $this->rules->bool('messaging.reminder_1_enabled')) {
            return 'disabled';
        }
        if ($log->type === MessageType::AttendanceReminderShort) {
            if (! $this->rules->bool('messaging.reminder_2_enabled') || ! $lessonRule['second_reminder_enabled']) {
                return 'circle_disabled';
            }
            if ($log->student_id && AttendanceConfirmation::where('lesson_session_id', $session->id)->where('student_id', $log->student_id)->exists()) {
                return 'confirmed';
            }
        }
        if ($log->student_id) {
            if (Attendance::where('lesson_session_id', $session->id)->where('student_id', $log->student_id)->where('status', AttendanceStatus::Excused->value)->exists()) {
                return 'excused';
            }
            if (! LessonStudent::where('lesson_id', $session->lesson_id)->where('student_id', $log->student_id)->where('status', LessonStudentStatus::Active->value)->exists()) {
                return 'not_enrolled';
            }
        }

        return null;
    }

    /**
     * A planned message fell due (or "send now"): re-check it, refresh a reminder's text with the
     * session as it is now, then queue it. Returns the resulting status.
     */
    public function release(MessageLog $log): MessageStatus
    {
        if ($reason = $this->skipReason($log)) {
            $log->update(['status' => MessageStatus::Skipped, 'error' => $reason]);

            return MessageStatus::Skipped;
        }

        $at = $this->sendAt();
        if ($at->greaterThan(now())) {
            $log->update(['scheduled_for' => $at]);

            return MessageStatus::Scheduled;
        }

        $user = $log->user_id ? User::find($log->user_id) : null;
        if ($reason = $this->guard->blockedReason($log->recipient_phone, $log->type ?? MessageType::Custom, $user)) {
            $log->update(['status' => MessageStatus::Suppressed, 'error' => $reason]);

            return MessageStatus::Suppressed;
        }

        $update = ['status' => MessageStatus::Queued];
        if ($log->type?->isReminder() && $log->session && $log->student) {
            $student = $log->student;
            $recipient = ['locale' => $log->locale, 'guardian_name' => (string) ($student->guardian_name ?: $student->guardian?->name ?? '')];
            $update['body'] = $this->renderer->render((string) $log->template_key, $this->varsFor($log->session, $student, $recipient), $log->locale);
        }
        $log->update($update);
        $this->messages->dispatch($log, false);

        if ($log->type === MessageType::AttendanceReminderLong && $log->session && ! $log->session->reminder_sent_at) {
            $log->session->forceFill(['reminder_sent_at' => now()])->saveQuietly();
        }

        return MessageStatus::Queued;
    }

    /**
     * "Send now": the first reminder to the whole circle immediately (quiet hours still apply).
     * A recipient who already has it for this session and time is skipped.
     *
     * @return array{queued: int, skipped: int}
     */
    public function sendNow(LessonSession $session): array
    {
        $queued = 0;
        $skipped = 0;
        $type = MessageType::AttendanceReminderLong;

        foreach ($this->enrolled($session) as $ls) {
            foreach ($this->recipients($ls->student, $session->session_date) as $r) {
                $key = $this->dedupeKey($session, $type, $r["phone"], true, $ls->student_id);
                $existing = MessageLog::where('dedupe_key', $key)->first();
                if ($existing) {
                    if ($existing->status === MessageStatus::Scheduled && in_array($this->release($existing), [MessageStatus::Queued, MessageStatus::Scheduled], true)) {
                        $queued++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }
                $log = $this->messages->send(
                    phone: $r['phone'], type: $type, vars: $this->varsFor($session, $ls->student, $r, $ls), locale: $r['locale'],
                    student: $ls->student, user: $r['user'], recipientType: $r['type'],
                    session: $session, sendAt: $this->sendAt(), dedupeKey: $key,
                );
                $log ? $queued++ : $skipped++;
            }
        }

        if ($queued && ! $session->reminder_sent_at) {
            $session->forceFill(['reminder_sent_at' => now()])->saveQuietly();
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------ event-driven

    /** After attendance is saved: guardians of students marked absent (not late, not excused). */
    public function sendAbsenceNotices(LessonSession $session): int
    {
        $absences = Attendance::with(['student.guardian', 'student.user'])
            ->where('lesson_session_id', $session->id)
            ->where('status', AttendanceStatus::Absent->value)
            ->whereNull('absence_notified_at')
            ->get();

        if (! $this->rules->bool('messaging.absence_enabled')) {
            return 0;
        }

        $sent = 0;
        foreach ($absences as $attendance) {
            $student = $attendance->student;
            if (! $student) {
                continue;
            }
            foreach ($this->recipients($student, $session->session_date, includeStudent: false) as $r) {
                $vars = $this->varsFor($session, $student, $r);
                $vars['assignment'] = $attendance->memorization_assignment ?: $vars['assignment'];
                $log = $this->messages->send(
                    phone: $r['phone'], type: MessageType::AbsenceNotice, vars: $vars, locale: $r['locale'],
                    student: $student, user: $r['user'], recipientType: $r['type'],
                    session: $session, sendAt: $this->sendAt(), dedupeKey: $this->dedupeKey($session, MessageType::AbsenceNotice, $r["phone"], false, $student->id),
                );
                $sent += $log ? 1 : 0;
            }
            $attendance->update(['absence_notified_at' => now()]);
        }

        return $sent;
    }

    /**
     * 3 absences in 30 days (settings): the guardian gets repeated_absence, at most once per 14 days
     * per student. The supervisor alert is raised by RepeatedAbsenceDetector.
     */
    public function sendRepeatedAbsence(Student $student, int $count, ?LessonSession $session = null): int
    {
        if (! $this->rules->bool('messaging.repeated_absence_enabled')) {
            return 0;
        }
        $since = now()->subDays(max(1, $this->rules->int('messaging.repeated_absence_throttle_days')));
        $recent = MessageLog::where('student_id', $student->id)
            ->where('template_key', MessageType::RepeatedAbsence->value)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', MessageStatus::Cancelled->value)
            ->exists();
        if ($recent) {
            return 0;
        }

        $sent = 0;
        foreach ($this->recipients($student, now(), includeStudent: false) as $r) {
            $vars = $session ? $this->varsFor($session, $student, $r) : ['name' => $student->full_name, 'guardian_name' => $r['guardian_name'], 'supervisor_phone' => $this->rules->supervisorPhone()];
            $log = $this->messages->send(
                phone: $r['phone'], type: MessageType::RepeatedAbsence, vars: $vars + ['absence_count' => (string) $count], locale: $r['locale'],
                student: $student, user: $r['user'], recipientType: $r['type'],
                session: $session, sendAt: $this->sendAt(),
            );
            $sent += $log ? 1 : 0;
        }

        return $sent;
    }

    /**
     * A location override or lesson location change saved with "notify": all guardians of the circle
     * and students 12+. Called through StudentMessenger once per student.
     *
     * @param  array{lesson?: string, lesson_id?: int, date?: string, location?: string, map_link?: string}  $vars
     */
    public function locationChange(Student $student, array $vars): int
    {
        if (! $this->rules->bool('messaging.location_change_enabled')) {
            return 0;
        }

        $session = $this->sessionFor($student, $vars);
        $isDate = isset($vars['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $vars['date']);
        $sent = 0;

        foreach ($this->recipients($student, $session?->session_date ?? now()) as $r) {
            $base = $session ? $this->varsFor($session, $student, $r) : ['name' => $student->full_name, 'guardian_name' => $r['guardian_name']];
            $date = $session ? $base['date'] : ($isDate ? $this->formatDate(Carbon::parse($vars['date']), $r['locale']) : __('lessons.all_upcoming', [], $r['locale']));
            $v = array_merge($base, array_filter([
                'lesson' => $vars['lesson'] ?? null,
                'location' => $vars['location'] ?? null,
                'map_link' => $vars['map_link'] ?? null,
            ], fn ($x) => $x !== null), ['date' => $date]);
            if (! $session && ! isset($v['time'])) {
                $v['time'] = $this->lessonTime($student, $vars);
            }

            $where = md5(($vars['location'] ?? '').'|'.($vars['map_link'] ?? ''));
            $key = $session
                ? $this->dedupeKey($session, MessageType::LocationChange, $r['phone']).':'.$where
                : 'l'.($vars['lesson_id'] ?? md5((string) ($vars['lesson'] ?? ''))).':location_change:'.$r['phone'].':'.($vars['date'] ?? 'upcoming').':'.$where.':'.now()->format('YmdHi');

            $log = $this->messages->send(
                phone: $r['phone'], type: MessageType::LocationChange, vars: $v, locale: $r['locale'],
                student: $student, user: $r['user'], recipientType: $r['type'],
                session: $session, sendAt: $this->sendAt(), dedupeKey: $key,
            );
            $sent += $log ? 1 : 0;
        }

        return $sent;
    }

    /** The session a location-change notice is about: by lesson id (or name) and date. */
    private function sessionFor(Student $student, array $vars): ?LessonSession
    {
        if (! isset($vars['date']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $vars['date'])) {
            return null;
        }
        $lessonIds = isset($vars['lesson_id'])
            ? [(int) $vars['lesson_id']]
            : $student->activeLessons()->when(isset($vars['lesson']), fn ($q) => $q->where('lessons.name', $vars['lesson']))->pluck('lessons.id')->all();

        return LessonSession::whereIn('lesson_id', $lessonIds)->where('session_date', $vars['date'])->first();
    }

    private function lessonTime(Student $student, array $vars): string
    {
        $lesson = isset($vars['lesson_id'])
            ? \App\Models\Lesson::find($vars['lesson_id'])
            : $student->activeLessons()->when(isset($vars['lesson']), fn ($q) => $q->where('lessons.name', $vars['lesson']))->first();

        return $lesson ? substr(WeekDays::time((string) $lesson->start_time), 0, 5) : '';
    }

    /** A session was cancelled: withdraw what is pending and tell the circle (if it has not started). */
    public function sessionCancelled(LessonSession $session): int
    {
        $this->cancelPending($session, 'session_cancelled');

        if (! $this->rules->bool('messaging.session_cancelled_enabled') || now()->gte($this->startsAt($session))) {
            return 0;
        }

        $sent = 0;
        foreach ($this->enrolled($session) as $ls) {
            foreach ($this->recipients($ls->student, $session->session_date) as $r) {
                $log = $this->messages->send(
                    phone: $r['phone'], type: MessageType::SessionCancelled, vars: $this->varsFor($session, $ls->student, $r, $ls), locale: $r['locale'],
                    student: $ls->student, user: $r['user'], recipientType: $r['type'],
                    session: $session, sendAt: $this->sendAt(), dedupeKey: $this->dedupeKey($session, MessageType::SessionCancelled, $r['phone'], true),
                );
                $sent += $log ? 1 : 0;
            }
        }

        return $sent;
    }

    /** Answer an inbound message (quiet hours apply; opt-out does not, the person just wrote to us). */
    public function reply(string $phone, MessageType $type, string $locale, array $vars = [], ?Student $student = null, ?User $user = null, ?LessonSession $session = null, ?string $dedupeKey = null): ?\App\Models\MessageLog
    {
        return $this->messages->send(
            phone: $phone, type: $type, vars: $vars + ['name' => $student?->full_name ?? $user?->name ?? ''], locale: $locale,
            student: $student, user: $user, recipientType: $student && $student->student_phone && PhoneNumber::normalize($student->student_phone) === PhoneNumber::normalize($phone) ? RecipientType::Student : ($student ? RecipientType::Guardian : RecipientType::User),
            session: $session, sendAt: $this->sendAt(), dedupeKey: $dedupeKey,
        );
    }
}
