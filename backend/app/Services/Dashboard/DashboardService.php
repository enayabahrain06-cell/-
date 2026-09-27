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
use App\Support\Track;
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
            'scope' => [
                'track' => Track::genderFor($user)?->value ?? 'both',
                'own_circles_only' => $this->teacherOnly($user),
            ],
            'kpis' => $this->kpis($user, $today, $tz),
            'today' => $this->todaySessions($user, $today),
            'attendance_chart' => $this->attendanceChart($user, $today, 14),
            'alerts' => $this->alerts($user, $locale),
        ];
    }

    public function teacherOnly(User $user): bool
    {
        return ! $user->can('lessons.manage');
    }

    /** Circles the user may see on the dashboard. */
    public function lessonScope(User $user): Builder
    {
        return Lesson::query()
            ->when($this->teacherOnly($user), fn ($q) => $q->where('teacher_id', $user->id))
            ->tap(fn ($q) => Track::scope($q, $user));
    }

    private function studentIdsQuery(User $user)
    {
        return LessonStudent::where('status', LessonStudentStatus::Active->value)
            ->whereIn('lesson_id', $this->lessonScope($user)->select('id'))->select('student_id');
    }

    private function kpis(User $user, string $today, string $tz): array
    {
        $students = Student::where('status', StudentStatus::Active->value)
            ->when($this->teacherOnly($user), fn ($q) => $q->whereIn('id', $this->studentIdsQuery($user)))
            ->tap(fn ($q) => Track::scope($q, $user));

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

    /**
     * "Alerts needing a decision": open alerts (conflicts, registrations, repeated absence, overdue invoices)
     * plus computed items (lotteries awaiting approval, exams in the next 3 days). Filtered to what the user may see.
     */
    private function alerts(User $user, string $locale): array
    {
        $items = Alert::with('subject')->where('status', AlertStatus::Open->value)->orderByDesc('id')->limit(200)->get()
            ->filter(fn (Alert $a) => $this->canSeeAlert($user, $a))
            ->map(fn (Alert $a) => [
                'id' => $a->id,
                'kind' => 'alert',
                'type' => $a->type->value,
                'type_label' => $a->type->label($locale),
                'severity' => $a->severity->value,
                'title' => $a->title,
                'body' => $a->body,
                'subject' => $a->subject_type ? ['type' => class_basename($a->subject_type), 'id' => $a->subject_id] : null,
                'created_at' => display_tz($a->created_at)?->toIso8601String(),
                'resolvable' => $user->can('lessons.manage') || $user->can('registrations.manage'),
            ])->values();

        if ($user->can('lottery.view')) {
            Lottery::with('package:id,name')->where('status', LotteryStatus::Run->value)
                ->tap(fn ($q) => Track::scope($q, $user))->get()
                ->each(fn (Lottery $l) => $items->push([
                    'id' => null, 'kind' => 'computed', 'type' => AlertType::LotteryPending->value,
                    'type_label' => AlertType::LotteryPending->label($locale), 'severity' => 'warning',
                    'title' => __('dashboard.lottery_pending', ['name' => $l->name], $locale), 'body' => $l->package?->name,
                    'subject' => ['type' => 'Lottery', 'id' => $l->id], 'created_at' => display_tz($l->run_at)?->toIso8601String(), 'resolvable' => false,
                ]));
        }

        if ($user->can('exams.view')) {
            Exam::where('status', ExamStatus::Published->value)
                ->whereBetween('opens_at', [now(), now()->addDays(3)])
                ->when($this->teacherOnly($user) && ! $user->can('exams.manage'), fn ($q) => $q->whereIn('lesson_id', $this->lessonScope($user)->select('id')))
                ->tap(fn ($q) => Track::scope($q, $user))->orderBy('opens_at')->get()
                ->each(fn (Exam $e) => $items->push([
                    'id' => null, 'kind' => 'computed', 'type' => AlertType::ExamUpcoming->value,
                    'type_label' => AlertType::ExamUpcoming->label($locale), 'severity' => 'info',
                    'title' => __('dashboard.exam_upcoming', ['name' => $e->name], $locale), 'body' => display_tz($e->opens_at)?->format('Y-m-d H:i'),
                    'subject' => ['type' => 'Exam', 'id' => $e->id], 'created_at' => display_tz($e->opens_at)?->toIso8601String(), 'resolvable' => false,
                ]));
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];

        return [
            'total' => $items->count(),
            'by_type' => $items->countBy('type')->all(),
            'items' => $items->sortBy(fn ($i) => $order[$i['severity']] ?? 3)->values()->take(30)->all(),
        ];
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
