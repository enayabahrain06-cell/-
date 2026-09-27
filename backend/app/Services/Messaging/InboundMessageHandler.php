<?php

namespace App\Services\Messaging;

use App\Enums\AttendanceStatus;
use App\Enums\ExcuseStatus;
use App\Enums\InboundIntent;
use App\Enums\InboundStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\AttendanceConfirmation;
use App\Models\AttendanceExcuse;
use App\Models\InboundMessage;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Services\WhatsApp\Inbound\InboundEvent;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inbound WhatsApp replies (section 23):
 * - "حاضر" / "Yes"      → attendance_confirmations for the sender's children with a session today;
 * - "عذر" / "Excuse"     → excused (before attendance is taken) or a pending excuse; reply excuse_received;
 * - "إيقاف" / "Stop"     → notifications off, reply notifications_stopped; "تشغيل" / "Start" → back on;
 * - anything else        → supervisor inbox, reply auto_reply_generic at most once per 24 hours.
 * The sender is matched by phone (PhoneNumber::normalize) to guardians and students.
 */
class InboundMessageHandler
{
    public const EXCUSE_NOTE = 'عذر من ولي الأمر عبر الواتساب';

    private const KEYWORDS = [
        'confirm' => ['حاضر', 'حاضره', 'yes'],
        'excuse' => ['عذر', 'excuse'],
        'stop' => ['ايقاف', 'stop'],
        'start' => ['تشغيل', 'start'],
    ];

    public function __construct(
        private AttendanceMessenger $messenger,
        private DeliveryGuard $guard,
        private MessagingRules $rules,
    ) {}

    public function handle(InboundEvent $event, ?string $provider = null): ?InboundMessage
    {
        if ($event->kind === InboundEvent::ACK) {
            $this->ack($event);

            return null;
        }

        $phone = PhoneNumber::normalize($event->from);
        if (! $phone || $event->body === null) {
            return null;
        }
        if ($event->providerMessageId && ($seen = InboundMessage::where('provider_message_id', $event->providerMessageId)->first())) {
            return $seen; // webhook retried
        }

        $intent = $this->intent($event->body);
        $students = $this->studentsFor($phone);
        $users = User::where('phone', $phone)->get();
        $user = $users->first() ?? $students->first()?->guardian;
        $locale = $user?->locale?->value ?? $students->first()?->locale?->value ?? $this->scriptLocale($event->body);
        $sessions = $this->todaySessions($students);

        $inbound = InboundMessage::create([
            'from_phone' => $phone,
            'body' => mb_substr($event->body, 0, 4000),
            'provider' => $provider,
            'provider_message_id' => $event->providerMessageId,
            'intent' => $intent,
            'user_id' => $user?->id,
            'student_id' => $students->first()?->id,
            'lesson_session_id' => $sessions->first()?->id,
            'status' => InboundStatus::Processed,
            'received_at' => $event->at ?? now(),
        ]);

        match ($intent) {
            InboundIntent::Confirm => $this->confirm($inbound, $phone, $sessions),
            InboundIntent::Excuse => $this->excuse($inbound, $phone, $students, $locale, $user),
            InboundIntent::Stop => $this->stop($inbound, $phone, $locale, $students->first(), $user),
            InboundIntent::Start => $this->start($inbound, $phone, $locale, $students->first(), $user),
            InboundIntent::Other => $this->other($inbound, $phone, $locale, $students->first(), $user),
        };

        return $inbound->fresh();
    }

    public function intent(string $body): InboundIntent
    {
        $text = self::normalize($body);
        $first = explode(' ', $text)[0] ?? '';

        return match (true) {
            in_array($text, self::KEYWORDS['stop'], true) => InboundIntent::Stop,
            in_array($text, self::KEYWORDS['start'], true) => InboundIntent::Start,
            in_array($text, self::KEYWORDS['confirm'], true) => InboundIntent::Confirm,
            in_array($first, self::KEYWORDS['excuse'], true) => InboundIntent::Excuse,
            default => InboundIntent::Other,
        };
    }

    /** Lower-case, strip diacritics / tatweel / punctuation / emoji, unify alef, teh marbuta and yeh. */
    public static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $t = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    private function scriptLocale(string $body): string
    {
        return preg_match('/\p{Arabic}/u', $body) ? 'ar' : 'en';
    }

    /** Students whose guardian or own phone is this number (directly or through their user accounts). */
    public function studentsFor(string $phone): Collection
    {
        $userIds = User::where('phone', $phone)->pluck('id')->all();

        return Student::with(['guardian', 'user'])
            ->where(function ($q) use ($phone, $userIds) {
                $q->where('guardian_phone', $phone)->orWhere('student_phone', $phone);
                if ($userIds) {
                    $q->orWhereIn('guardian_user_id', $userIds)->orWhereIn('user_id', $userIds);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, LessonSession> today's (local date) non-cancelled sessions of these students' circles, keyed by student id */
    private function todaySessions(Collection $students): Collection
    {
        $out = collect();
        $today = $this->messenger->localToday();
        foreach ($students as $student) {
            $lessonIds = LessonStudent::where('student_id', $student->id)->where('status', LessonStudentStatus::Active->value)->pluck('lesson_id');
            $session = LessonSession::whereIn('lesson_id', $lessonIds)->where('session_date', $today)
                ->where('status', '!=', SessionStatus::Cancelled->value)->orderBy('start_time')->first();
            if ($session) {
                $out->put($student->id, $session);
            }
        }

        return $out;
    }

    /** @param Collection<int, LessonSession> $sessions keyed by student id */
    private function confirm(InboundMessage $inbound, string $phone, Collection $sessions): void
    {
        if ($sessions->isEmpty()) {
            $this->toInbox($inbound, $phone);

            return;
        }

        foreach ($sessions as $studentId => $session) {
            AttendanceConfirmation::firstOrCreate(
                ['lesson_session_id' => $session->id, 'student_id' => $studentId],
                ['confirmed_at' => now(), 'via' => 'whatsapp', 'phone' => $phone]
            );
        }
        // The second reminder is skipped when it falls due (AttendanceMessenger::skipReason).
    }

    private function excuse(InboundMessage $inbound, string $phone, Collection $students, string $locale, ?User $user): void
    {
        $handled = [];
        $today = $this->messenger->localToday();

        foreach ($students as $student) {
            $session = $this->excuseSession($student, $today);
            if (! $session) {
                continue;
            }
            $handled[] = [$student, $session];

            DB::transaction(function () use ($student, $session, $phone, $inbound) {
                $attendance = Attendance::where('lesson_session_id', $session->id)->where('student_id', $student->id)->first();
                $already = AttendanceExcuse::where('lesson_session_id', $session->id)->where('student_id', $student->id)
                    ->whereIn('status', [ExcuseStatus::Applied->value, ExcuseStatus::Pending->value, ExcuseStatus::Approved->value])->exists();
                if ($already) {
                    return;
                }

                if ($session->attendance_taken_at === null) {
                    $attendance = Attendance::updateOrCreate(
                        ['lesson_session_id' => $session->id, 'student_id' => $student->id],
                        ['status' => AttendanceStatus::Excused, 'note' => self::EXCUSE_NOTE, 'recorded_by' => null]
                    );
                    $status = ExcuseStatus::Applied;
                } else {
                    $status = $attendance?->status === AttendanceStatus::Excused ? ExcuseStatus::Applied : ExcuseStatus::Pending;
                }

                AttendanceExcuse::create([
                    'lesson_session_id' => $session->id,
                    'student_id' => $student->id,
                    'attendance_id' => $attendance?->id,
                    'inbound_message_id' => $inbound->id,
                    'phone' => $phone,
                    'body' => $inbound->body,
                    'source' => 'whatsapp',
                    'status' => $status,
                ]);
            });
        }

        if (! $handled) {
            $this->toInbox($inbound, $phone);

            return;
        }

        $inbound->update(['lesson_session_id' => $handled[0][1]->id, 'student_id' => $handled[0][0]->id]);
        $names = implode($locale === 'en' ? ', ' : '، ', array_map(fn ($h) => $h[0]->full_name, $handled));
        $this->messenger->reply($phone, MessageType::ExcuseReceived, $locale, ['name' => $names], $handled[0][0], $user, $handled[0][1],
            dedupeKey: $this->messenger->dedupeKey($handled[0][1], MessageType::ExcuseReceived, $phone));
    }

    /** Today's session, else the latest absence in the last 3 days (a reply to an absence notice). */
    private function excuseSession(Student $student, string $today): ?LessonSession
    {
        $lessonIds = LessonStudent::where('student_id', $student->id)->where('status', LessonStudentStatus::Active->value)->pluck('lesson_id');
        $session = LessonSession::whereIn('lesson_id', $lessonIds)->where('session_date', $today)
            ->where('status', '!=', SessionStatus::Cancelled->value)->orderBy('start_time')->first();
        if ($session) {
            return $session;
        }

        $since = \Carbon\Carbon::parse($today)->subDays(3)->toDateString();

        return LessonSession::whereHas('attendances', fn ($q) => $q->where('student_id', $student->id)->where('status', AttendanceStatus::Absent->value))
            ->where('session_date', '>=', $since)->where('session_date', '<=', $today)
            ->orderByDesc('session_date')->first();
    }

    private function stop(InboundMessage $inbound, string $phone, string $locale, ?Student $student, ?User $user): void
    {
        $this->guard->optOut($phone);
        $this->messenger->reply($phone, MessageType::NotificationsStopped, $locale, [], $student, $user);
    }

    private function start(InboundMessage $inbound, string $phone, string $locale, ?Student $student, ?User $user): void
    {
        $this->guard->optIn($phone);
        $this->messenger->reply($phone, MessageType::NotificationsResumed, $locale, [], $student, $user);
    }

    private function other(InboundMessage $inbound, string $phone, string $locale, ?Student $student, ?User $user): void
    {
        $this->toInbox($inbound, $phone, $locale, $student, $user);
    }

    /** Free text (or a keyword we could not match to a session): supervisor inbox + one auto-reply per 24 h. */
    private function toInbox(InboundMessage $inbound, string $phone, ?string $locale = null, ?Student $student = null, ?User $user = null): void
    {
        $inbound->update(['status' => InboundStatus::Open]);

        $hours = max(1, $this->rules->int('messaging.auto_reply_hours'));
        $recent = MessageLog::where('recipient_phone', $phone)
            ->where('template_key', MessageType::AutoReplyGeneric->value)
            ->where('created_at', '>=', now()->subHours($hours))
            ->where('status', '!=', MessageStatus::Cancelled->value)
            ->exists();
        if ($recent) {
            return;
        }

        $student ??= $inbound->student;
        $user ??= $inbound->user;
        $locale ??= $user?->locale?->value ?? $student?->locale?->value ?? $this->scriptLocale((string) $inbound->body);
        $this->messenger->reply($phone, MessageType::AutoReplyGeneric, $locale, [], $student, $user);
    }

    /** Delivery receipts: sent → delivered → read only move forward; failed counts toward "invalid number". */
    private function ack(InboundEvent $event): void
    {
        $log = MessageLog::where('provider_message_id', $event->providerMessageId)->first();
        if (! $log) {
            return;
        }

        $rank = [MessageStatus::Sent->value => 1, MessageStatus::Delivered->value => 2, MessageStatus::Read->value => 3];
        $current = $rank[$log->status?->value] ?? 0;

        match ($event->ackStatus) {
            'delivered' => $current < 2 ? $log->update(['status' => MessageStatus::Delivered, 'delivered_at' => now()]) : null,
            'read' => $current < 3 ? $log->update(['status' => MessageStatus::Read, 'read_at' => now(), 'delivered_at' => $log->delivered_at ?? now()]) : null,
            'failed', 'undelivered', 'error' => $this->ackFailed($log, $event->error),
            default => null,
        };

        // A confirmed delivery proves the number works: its failure count starts again.
        if (in_array($event->ackStatus, ['delivered', 'read'], true)) {
            $this->guard->recordSuccess($log->recipient_phone);
        }
    }

    private function ackFailed(MessageLog $log, ?string $error): void
    {
        if ($log->status === MessageStatus::Failed) {
            return;
        }
        $log->update(['status' => MessageStatus::Failed, 'error' => $error ?? 'delivery failed']);
        $this->guard->recordFailure($log->recipient_phone, $error);
    }
}
