<?php

namespace App\Services\Dashboard;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\ExamStatus;
use App\Enums\LessonStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\LotteryStatus;
use App\Enums\RegistrationStatus;
use App\Enums\SessionStatus;
use App\Enums\StudentStatus;
use App\Models\Alert;
use App\Models\Attendance;
use App\Models\Exam;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Lottery;
use App\Models\Package;
use App\Models\Payment;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Lessons\LocationConflictDetector;
use App\Support\Track;
use App\Support\WeekDays;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Dashboard payload for staff. Everything is scoped: managers to their gender track,
 * teachers (without lessons.manage) to their own circles. Aggregation is done in PHP (portable).
 */
class DashboardService
{
    public function build(User $user, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $today = now($tz)->toDateString();

        return [
            'date' => $today,
            'generated_at' => now($tz)->toIso8601String(),
            'scope' => [
                'track' => Track::genderFor($user)?->value ?? 'both',
                'own_circles_only' => $this->teacherOnly($user),
            ],
            'kpis' => $this->kpis($user, $today, $tz),
            'today' => $this->todaySessions($user, $today),
            'attendance_chart' => $this->attendanceChart($user, $today, 14),
            'age_distribution' => $this->ageDistribution($user, $today),
            'alerts' => $this->alerts($user, $locale),
        ];
    }

    public function teacherOnly(User $user): bool
    {
        return ! $user->can('lessons.manage');
    }

    /** Circles the user may see on the dashboard. */
    public function lessonScope(User $user, ?string $term = null): Builder
    {
        return Lesson::query()
            ->when($this->teacherOnly($user), fn ($q) => $q->where('teacher_id', $user->id))
            ->when($term, fn ($q) => $q->whereHas('package', fn ($p) => $p->where('term', $term)))
            ->tap(fn ($q) => Track::scope($q, $user));
    }

    private function studentIdsQuery(User $user)
    {
        return LessonStudent::where('status', LessonStudentStatus::Active->value)
            ->whereIn('lesson_id', $this->lessonScope($user)->select('id'))->select('student_id');
    }

    /** Active students the user may see (teachers: those in their circles). */
    private function activeStudents(User $user): Builder
    {
        return Student::where('status', StudentStatus::Active->value)
            ->when($this->teacherOnly($user), fn ($q) => $q->whereIn('id', $this->studentIdsQuery($user)))
            ->tap(fn ($q) => Track::scope($q, $user));
    }

    /** Fixed age bands (≤6 matches the early-years packages); age in whole years on the display-timezone date. */
    public const AGE_BANDS = [[0, 6], [7, 9], [10, 12], [13, 15], [16, 18], [19, null]];

    private function ageDistribution(User $user, string $today): array
    {
        $on = Carbon::parse($today);
        $ages = $this->activeStudents($user)->pluck('birth_date')
            ->map(fn ($d) => $d ? (int) Carbon::parse($d)->diffInYears($on) : null)->filter(fn ($a) => $a !== null);

        return [
            'total' => $ages->count(),
            'average' => $ages->isEmpty() ? null : round($ages->avg(), 1),
            'bands' => collect(self::AGE_BANDS)->map(fn ($b) => [
                'key' => $b[1] === null ? "{$b[0]}+" : "{$b[0]}-{$b[1]}",
                'min' => $b[0],
                'max' => $b[1],
                'count' => $ages->filter(fn ($a) => $a >= $b[0] && ($b[1] === null || $a <= $b[1]))->count(),
            ])->all(),
        ];
    }

    private function kpis(User $user, string $today, string $tz): array
    {
        $students = $this->activeStudents($user);

        $lessonIds = $this->lessonScope($user)->where('status', LessonStatus::Active->value)->pluck('id');

        $todaySessions = LessonSession::whereIn('lesson_id', $this->lessonScope($user)->select('id'))
            ->where('session_date', $today)->where('status', '!=', SessionStatus::Cancelled->value)->get(['id', 'attendance_taken_at']);

        // Attendance rate over the last 7 days.
        $week = $this->attendanceRows($user, Carbon::parse($today)->subDays(6)->toDateString(), $today);
        $counted = $week->reject(fn ($a) => $a->status->value === 'excused');
        $attended = $counted->filter(fn ($a) => in_array($a->status->value, ['present', 'late'], true))->count();

        $kpis = [
            'active_students' => (clone $students)->count(),
            'active_circles' => $lessonIds->count(),
            'sessions_today' => $todaySessions->count(),
            'attendance_taken_today' => $todaySessions->whereNotNull('attendance_taken_at')->count(),
            'attendance_rate_7d' => $counted->isEmpty() ? null : (int) round($attended * 100 / $counted->count()),
        ];

        if ($user->can('registrations.view')) {
            $kpis['pending_registrations'] = RegistrationRequest::where('status', RegistrationStatus::Pending->value)
                ->tap(fn ($q) => Track::scope($q, $user))->count();
        }

        if ($user->can('wallets.view')) {
            $monthStart = now($tz)->startOfMonth()->utc();
            $kpis['collected_this_month_fils'] = (int) Payment::where('paid_at', '>=', $monthStart)
                ->tap(fn ($q) => Track::scopeVia($q, $user, 'student'))->sum('amount_fils');
            $due = Wallet::where('balance_fils', '<', 0)->tap(fn ($q) => Track::scopeVia($q, $user, 'student'));
            $kpis['students_due'] = (clone $due)->count();
            $kpis['outstanding_fils'] = (int) -(clone $due)->sum('balance_fils');
        }

        return $kpis;
    }

    /** Today's sessions with hall status: ok, changed (one-day override), conflict (open alert), no_hall, cancelled. */
    private function todaySessions(User $user, string $today): array
    {
        $sessions = LessonSession::with(['lesson.teacher:id,name', 'location:id,name,gender'])
            ->withCount([
                'attendances as present_count' => fn ($q) => $q->whereIn('status', ['present', 'late']),
                'attendances as absent_count' => fn ($q) => $q->where('status', 'absent'),
            ])
            ->whereIn('lesson_id', $this->lessonScope($user)->select('id'))
            ->where('session_date', $today)
            ->orderBy('start_time')->get();

        $lessonIds = $sessions->pluck('lesson_id')->unique();
        $overrides = LessonLocationOverride::whereIn('lesson_id', $lessonIds)->where('override_date', $today)->pluck('lesson_id')->flip();
        $conflicts = Alert::where('type', AlertType::LocationConflict->value)->where('status', AlertStatus::Open->value)
            ->where('subject_type', (new Lesson)->getMorphClass())->whereIn('subject_id', $lessonIds)->pluck('subject_id')->flip();
        $enrolled = LessonStudent::whereIn('lesson_id', $lessonIds)->where('status', LessonStudentStatus::Active->value)
            ->get(['lesson_id'])->countBy('lesson_id');

        return $sessions->map(function (LessonSession $s) use ($overrides, $conflicts, $enrolled) {
            $status = match (true) {
                $s->status === SessionStatus::Cancelled => 'cancelled',
                $conflicts->has($s->lesson_id) => 'conflict',
                ! $s->location_id => 'no_hall',
                $overrides->has($s->lesson_id) => 'changed',
                default => 'ok',
            };

            return [
                'id' => $s->id,
                'lesson_id' => $s->lesson_id,
                'lesson' => $s->lesson?->name,
                'gender' => $s->lesson?->gender?->value,
                'teacher' => $s->lesson?->teacher?->name,
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'location' => $s->location?->name,
                'location_status' => $status,
                'session_status' => $s->status->value,
                'attendance_taken' => $s->attendance_taken_at !== null,
                'enrolled' => (int) ($enrolled[$s->lesson_id] ?? 0),
                'present' => (int) $s->present_count,
                'absent' => (int) $s->absent_count,
            ];
        })->values()->all();
    }

    /** One row per day: present, late, absent, excused, rate. */
    private function attendanceChart(User $user, string $today, int $days): array
    {
        $from = Carbon::parse($today)->subDays($days - 1);
        $rows = $this->attendanceRows($user, $from->toDateString(), $today)
            ->groupBy(fn ($a) => $a->session->session_date->toDateString());

        $out = [];
        for ($d = $from->copy(); $d->lte(Carbon::parse($today)); $d->addDay()) {
            $day = $rows->get($d->toDateString(), collect())->countBy(fn ($a) => $a->status->value);
            $counted = $day->sum() - ($day['excused'] ?? 0);
            $attended = ($day['present'] ?? 0) + ($day['late'] ?? 0);
            $out[] = [
                'date' => $d->toDateString(),
                'present' => (int) ($day['present'] ?? 0),
                'late' => (int) ($day['late'] ?? 0),
                'absent' => (int) ($day['absent'] ?? 0),
                'excused' => (int) ($day['excused'] ?? 0),
                'rate' => $counted > 0 ? (int) round($attended * 100 / $counted) : null,
            ];
        }

        return $out;
    }

    private function attendanceRows(User $user, string $from, string $to): Collection
    {
        return Attendance::with('session:id,session_date')
            ->whereHas('session', fn ($q) => $q->whereBetween('session_date', [$from, $to])
                ->whereIn('lesson_id', $this->lessonScope($user)->select('id')))
            ->get(['id', 'lesson_session_id', 'student_id', 'status']);
    }

    /** Dashboard box: counts over everything the user may see, plus the first 30 items. */
    private function alerts(User $user, string $locale): array
    {
        $items = $this->alertItems($user, $locale);

        return [
            'total' => $items->count(),
            'by_type' => $items->countBy('type')->all(),
            'items' => $items->take(30)->values()->all(),
        ];
    }

    /**
     * The alerts section (also "view all"): optionally filtered by type and term, paginated in PHP
     * (the list is permission-filtered). meta.by_type counts every type before the type filter.
     */
    public function alertsPage(User $user, ?string $type, int $page, int $perPage, ?string $locale = null, ?string $term = null): array
    {
        $all = $this->alertItems($user, $locale ?? app()->getLocale(), $term);
        $items = $all->when($type, fn ($c) => $c->where('type', $type))->values();
        $lastPage = max(1, (int) ceil($items->count() / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'data' => $items->forPage($page, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $page, 'last_page' => $lastPage, 'total' => $items->count(), 'per_page' => $perPage,
                'all_total' => $all->count(), 'by_type' => $all->countBy('type')->all(),
            ],
        ];
    }

    /**
     * "Alerts needing a decision": open alerts (conflicts, registrations, repeated absence, overdue invoices)
     * plus computed items (circles without a teacher, lotteries awaiting approval, exams in the next 3 days).
     * Filtered to what the user may see (and to one term when given), sorted by severity, newest first.
     */
    private function alertItems(User $user, string $locale, ?string $term = null): Collection
    {
        $resolvable = $user->can('lessons.manage') || $user->can('registrations.manage');
        $termLessons = $term ? $this->lessonScope($user, $term)->pluck('id')->flip() : null;
        $items = collect();
        $raw = collect();

        // Every open alert is checked (no fixed cap), so a busy track never pushes another track's alerts out.
        Alert::with('subject')->where('status', AlertStatus::Open->value)
            ->chunkById(500, function ($chunk) use ($user, $raw) {
                $chunk->filter(fn (Alert $a) => $this->canSeeAlert($user, $a))->each(fn (Alert $a) => $raw->push($a));
            });

        if ($termLessons !== null) {
            $raw = $raw->filter(fn (Alert $a) => $this->inTerm($a->subject, $term, $termLessons))->values();
        }

        $absentRuns = $this->consecutiveAbsences($raw->filter(fn (Alert $a) => $a->type === AlertType::RepeatedAbsence)->pluck('subject_id')->all());

        foreach ($raw as $a) {
            $item = [
                'id' => $a->id,
                'kind' => 'alert',
                'type' => $a->type->value,
                'type_label' => $a->type->label($locale),
                'severity' => $a->severity->value,
                'title' => $a->title,
                'body' => $a->body,
                'subject' => $this->subjectRef($a),
                'created_at' => display_tz($a->created_at)?->toIso8601String(),
                'resolvable' => $resolvable,
            ];

            if ($a->type === AlertType::LocationConflict && $a->subject instanceof Lesson) {
                $summary = $this->conflictSummary($a->subject, $locale);
                if ($summary) {
                    $item['conflict'] = $summary;
                    $item['title'] = __('dashboard.conflict_title', ['location' => $summary['location'] ?? '—', 'lesson' => $a->subject->name], $locale);
                    $item['body'] = $summary['text'];
                }
            }

            if ($a->type === AlertType::RepeatedAbsence && $a->subject instanceof Student) {
                $run = $absentRuns[$a->subject_id] ?? 0;
                $item['absence'] = ['consecutive' => $run, 'has_phone' => (bool) $a->subject->guardian_phone];
                if ($run >= 3) {
                    $item['body'] = __('dashboard.absent_in_a_row', ['count' => $run], $locale);
                }
            }

            $items->push($item);
        }
        $items = $items->sortByDesc('id')->values();

        // A circle with no (or an inactive) teacher: computed, not stored, so it clears itself once a teacher is set.
        if ($user->can('lessons.manage')) {
            $this->lessonScope($user, $term)->with('package:id,name')
                ->where('status', LessonStatus::Active->value)
                ->where(fn ($q) => $q->whereNull('teacher_id')->orWhereHas('teacher', fn ($t) => $t->where('is_active', false)))
                ->orderBy('name')->get()
                ->each(fn (Lesson $l) => $items->push([
                    'id' => null, 'kind' => 'computed', 'type' => AlertType::LessonWithoutTeacher->value,
                    'type_label' => AlertType::LessonWithoutTeacher->label($locale), 'severity' => 'warning',
                    'title' => __('dashboard.no_teacher_title', ['lesson' => $l->name], $locale), 'body' => $l->package?->name,
                    'subject' => ['type' => 'Lesson', 'id' => $l->id], 'created_at' => display_tz($l->updated_at)?->toIso8601String(), 'resolvable' => false,
                ]));
        }

        if ($user->can('lottery.view')) {
            Lottery::with('package:id,name,term')->where('status', LotteryStatus::Run->value)
                ->when($term, fn ($q) => $q->whereHas('package', fn ($p) => $p->where('term', $term)))
                ->tap(fn ($q) => Track::scope($q, $user))->get()
                ->each(fn (Lottery $l) => $items->push([
                    'id' => null, 'kind' => 'computed', 'type' => AlertType::LotteryPending->value,
                    'type_label' => AlertType::LotteryPending->label($locale), 'severity' => 'warning',
                    'title' => __('dashboard.lottery_pending', ['name' => $l->name], $locale), 'body' => $l->package?->name,
                    'subject' => ['type' => 'Lottery', 'id' => $l->id], 'created_at' => display_tz($l->run_at)?->toIso8601String(), 'resolvable' => false,
                ]));
        }

        if ($user->can('exams.view')) {
            Exam::forStudents()->where('status', ExamStatus::Published->value)
                ->whereBetween('opens_at', [now(), now()->addDays(3)])
                ->when($this->teacherOnly($user) && ! $user->can('exams.manage'), fn ($q) => $q->whereIn('lesson_id', $this->lessonScope($user)->select('id')))
                ->when($termLessons !== null, fn ($q) => $q->whereIn('lesson_id', $termLessons->keys()))
                ->tap(fn ($q) => Track::scope($q, $user))->orderBy('opens_at')->get()
                ->each(fn (Exam $e) => $items->push([
                    'id' => null, 'kind' => 'computed', 'type' => AlertType::ExamUpcoming->value,
                    'type_label' => AlertType::ExamUpcoming->label($locale), 'severity' => 'info',
                    'title' => __('dashboard.exam_upcoming', ['name' => $e->name], $locale), 'body' => display_tz($e->opens_at)?->format('Y-m-d H:i'),
                    'subject' => ['type' => 'Exam', 'id' => $e->id], 'created_at' => display_tz($e->opens_at)?->toIso8601String(), 'resolvable' => false,
                ]));
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];

        return $items->sortBy(fn ($i) => $order[$i['severity']] ?? 3)->values();
    }

    /** Does an alert's subject belong to the chosen term? Subjects without a term link are kept. */
    private function inTerm(?Model $subject, string $term, Collection $termLessons): bool
    {
        return match (true) {
            $subject instanceof Lesson => $termLessons->has($subject->id),
            $subject instanceof Package => $subject->term === $term,
            $subject instanceof Student => LessonStudent::where('student_id', $subject->id)->where('status', LessonStudentStatus::Active->value)
                ->whereIn('lesson_id', $termLessons->keys())->exists(),
            $subject instanceof Invoice => $subject->package_id === null || Package::whereKey($subject->package_id)->where('term', $term)->exists(),
            default => true,
        };
    }

    /**
     * One line instead of every date: "Conflict with Imam Nafi' circle — 23 sessions between 7 Sep and 17 Oct,
     * every Sun/Tue/Thu 4:00–5:30 PM", plus the full list for the "View sessions" dialog.
     * Recomputed from the detector so it is always current (the stored body is a snapshot).
     */
    public function conflictSummary(Lesson $lesson, string $locale): ?array
    {
        $lesson->loadMissing('location:id,name');
        $conflicts = collect(app(LocationConflictDetector::class)->forLesson($lesson));
        if ($conflicts->isEmpty()) {
            return null;
        }

        // One row per clashing occurrence. Dated items (sessions, bookings, one-day overrides) are used as is;
        // a recurring clash (weekday list, no date) is expanded into this circle's own sessions on those weekdays.
        $ownSessions = LessonSession::where('lesson_id', $lesson->id)->where('status', '!=', SessionStatus::Cancelled->value)
            ->orderBy('session_date')->get(['id', 'session_date', 'start_time', 'end_time']);
        $rows = $conflicts->flatMap(function ($c) use ($ownSessions) {
            if ($c['date']) {
                return [['date' => $c['date'], 'start_time' => $c['start_time'], 'end_time' => $c['end_time'], 'title' => $c['title'], 'kind' => $c['kind']]];
            }
            $days = $c['days'] ?? [];

            return $ownSessions->filter(fn ($s) => in_array(WeekDays::keyFor($s->session_date), $days, true)
                && WeekDays::overlaps($s->start_time, $s->end_time, $c['start_time'], $c['end_time']))
                ->map(fn ($s) => ['date' => $s->session_date->toDateString(), 'start_time' => $c['start_time'], 'end_time' => $c['end_time'], 'title' => $c['title'], 'kind' => $c['kind']])
                ->values()->all();
        })->unique(fn ($r) => $r['date'].'|'.$r['title'].'|'.$r['start_time'])->sortBy('date')->values();

        $dates = $rows->pluck('date');
        $weekdays = $dates->map(fn ($d) => Carbon::parse($d)->dayOfWeek)->unique()->sort()->values();
        $dayNames = $weekdays->map(fn ($d) => Carbon::create(2026, 1, 4)->addDays($d)->locale($locale)->isoFormat('ddd'))->implode($locale === 'ar' ? '، ' : '/');
        $time = fn (string $t) => Carbon::createFromFormat('H:i', substr($t, 0, 5))->locale($locale)->isoFormat('h:mm A');
        $with = $conflicts->pluck('title')->unique()->values();
        $fmt = fn ($d) => Carbon::parse($d)->locale($locale)->isoFormat('D MMMM');
        $start = WeekDays::time((string) $lesson->start_time);
        $end = WeekDays::time((string) $lesson->end_time);
        $count = max($rows->count(), $conflicts->count());

        $text = __('dashboard.conflict_summary', [
            'with' => $with->implode($locale === 'ar' ? '، ' : ', '),
            'count' => $count,
            'from' => $dates->isNotEmpty() ? $fmt($dates->first()) : '—',
            'to' => $dates->isNotEmpty() ? $fmt($dates->last()) : '—',
            'days' => $dayNames ?: '—',
            'time' => $time($start).'–'.$time($end),
        ], $locale);

        return [
            'location' => $lesson->location?->name,
            'location_id' => $lesson->location_id,
            'with' => $with->all(),
            'count' => $count,
            'from' => $dates->first(),
            'to' => $dates->last(),
            'weekdays' => $weekdays->all(),
            'start_time' => substr($start, 0, 5),
            'end_time' => substr($end, 0, 5),
            'text' => $text,
            'sessions' => $rows->all(),
        ];
    }

    /**
     * Current run of consecutive absences per student (latest attendance rows first; excused breaks nothing,
     * any present/late ends the run).
     *
     * @param  list<int>  $studentIds
     * @return array<int, int>
     */
    public function consecutiveAbsences(array $studentIds): array
    {
        if (! $studentIds) {
            return [];
        }

        return Attendance::with('session:id,session_date')->whereIn('student_id', $studentIds)
            ->whereHas('session', fn ($q) => $q->where('session_date', '>=', now()->subDays(120)->toDateString()))
            ->get(['id', 'lesson_session_id', 'student_id', 'status'])
            ->groupBy('student_id')
            ->map(function ($rows) {
                $run = 0;
                foreach ($rows->sortByDesc(fn ($r) => [$r->session->session_date->toDateString(), $r->id]) as $r) {
                    $s = $r->status->value;
                    if ($s === 'excused') {
                        continue;
                    }
                    if ($s !== 'absent') {
                        break;
                    }
                    $run++;
                }

                return $run;
            })->all();
    }


    /** Subject reference the UI turns into a link; invoices carry their student so the link opens the wallet. */
    private function subjectRef(Alert $alert): ?array
    {
        if (! $alert->subject_type) {
            return null;
        }
        $ref = ['type' => class_basename($alert->subject_type), 'id' => $alert->subject_id];
        if ($alert->subject instanceof Invoice) {
            $ref['student_id'] = $alert->subject->student_id;
        }

        return $ref;
    }

    public function canSeeAlert(User $user, Alert $alert): bool
    {
        $subject = $alert->subject;
        $gender = $this->subjectGender($subject);
        if (! Track::allows($user, $gender)) {
            return false;
        }

        return match ($alert->type) {
            AlertType::LocationConflict => $subject instanceof Lesson && ($user->can('lessons.manage') || $subject->teacher_id === $user->id),
            AlertType::RegistrationRequest => $user->can('registrations.view'),
            AlertType::RepeatedAbsence => $user->can('lessons.manage') || ($subject instanceof Student && \App\Policies\StudentPolicy::isTeacherOf($user, $subject)),
            AlertType::InvoiceOverdue => $user->can('wallets.view'),
            default => $user->can('lessons.manage'),
        };
    }

    private function subjectGender(?Model $subject): ?string
    {
        $g = match (true) {
            $subject instanceof Lesson, $subject instanceof Package, $subject instanceof Student,
            $subject instanceof Exam, $subject instanceof Lottery => $subject->gender,
            $subject instanceof Invoice => $subject->student?->gender,
            default => null,
        };

        return $g instanceof \BackedEnum ? (string) $g->value : $g;
    }
}
