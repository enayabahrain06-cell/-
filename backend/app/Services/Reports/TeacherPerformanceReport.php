<?php

namespace App\Services\Reports;

use App\Enums\LessonStatus;
use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\LessonSession;
use App\Models\User;
use Carbon\Carbon;

/**
 * Per teacher, over the circles in scope: active circles, sessions due in the period (up to today), held (attendance
 * taken), taken on the session day, cancelled, average evaluation given, and the students' attendance rate.
 */
class TeacherPerformanceReport
{
    /**
     * Per-teacher numbers over the circles in scope, keyed by teacher (user) id. Shared with the Teachers page.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, LessonSession>}
     */
    public function stats(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);

        $lessons = $ctx->lessonQuery()->with('teacher:id,name')->get(['id', 'name', 'teacher_id', 'status']);
        $sessions = LessonSession::whereIn('lesson_id', $ctx->lessonIds())
            ->whereBetween('session_date', [$ctx->fromDate(), $ctx->dueUntil()])
            ->get(['id', 'lesson_id', 'session_date', 'status', 'attendance_taken_at']);
        $attendance = Attendance::whereIn('lesson_session_id', $sessions->pluck('id')->all() ?: [0])->get(['id', 'lesson_session_id', 'status']);
        $evals = Evaluation::quran()->whereIn('lesson_id', $ctx->lessonIds())->whereBetween('evaluated_on', [$ctx->fromDate(), $ctx->toDate()])
            ->get(['id', 'lesson_id', 'memorization', 'tajweed', 'revision', 'behavior']);

        $lessonTeacher = $lessons->pluck('teacher_id', 'id');
        $sessionLesson = $sessions->pluck('lesson_id', 'id');
        $sessionsBy = $sessions->groupBy(fn ($s) => $lessonTeacher[$s->lesson_id] ?? 0);
        $attBy = $attendance->groupBy(fn ($a) => $lessonTeacher[$sessionLesson[$a->lesson_session_id] ?? 0] ?? 0);
        $evalBy = $evals->groupBy(fn ($e) => $lessonTeacher[$e->lesson_id] ?? 0);

        $stats = $lessons->groupBy('teacher_id')->map(function ($ls, $teacherId) use ($sessionsBy, $attBy, $evalBy, $ctx) {
            $s = $sessionsBy->get($teacherId, collect());
            $cancelled = $s->filter(fn ($x) => $x->status === SessionStatus::Cancelled)->count();
            $due = $s->reject(fn ($x) => $x->status === SessionStatus::Cancelled);
            $taken = $due->whereNotNull('attendance_taken_at');
            $onTime = $taken->filter(fn ($x) => Carbon::parse($x->attendance_taken_at)->setTimezone($ctx->tz)->toDateString() <= $x->session_date->toDateString());
            $ev = $evalBy->get($teacherId, collect());

            return [
                'name' => $ls->first()->teacher?->name,
                'circles' => $ls->filter(fn ($l) => $l->status === LessonStatus::Active)->count(),
                'sessions_due' => $due->count(),
                'held' => $taken->count(),
                'cancelled' => $cancelled,
                'taken_rate' => ReportContext::rate($taken->count(), $due->count()),
                'on_time_rate' => ReportContext::rate($onTime->count(), $taken->count()),
                'evaluations' => $ev->count(),
                'avg_evaluation' => ReportContext::avg($ev->map(fn ($e) => ($e->memorization + $e->tajweed + $e->revision + $e->behavior) / 4)),
                'attendance_rate' => ReportContext::tally($attBy->get($teacherId, collect()))['rate'],
            ];
        });

        return [$stats, $sessions];
    }

    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);
        [$stats, $sessions] = $this->stats($user, $f);
        $rows = $stats->map(fn ($t) => array_values($t))->sortBy(fn ($r) => $r[0])->values()->all();

        $h = fn (string $k) => __("reports.teachers.{$k}");
        $allDue = $sessions->reject(fn ($x) => $x->status === SessionStatus::Cancelled);

        return [
            'title' => __('reports.teachers.title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters(),
            'summary' => [
                [$h('teachers'), count($rows)],
                [$h('sessions_due'), $allDue->count()],
                [$h('taken_rate'), ($r = ReportContext::rate($allDue->whereNotNull('attendance_taken_at')->count(), $allDue->count())) === null ? '—' : $r.'%'],
                [$h('cancelled'), $sessions->count() - $allDue->count()],
            ],
            'sections' => [
                ['key' => 'teachers', 'title' => $h('list'),
                    'headings' => [__('reports.teacher'), $h('circles'), $h('sessions_due'), $h('held'), $h('cancelled'), $h('taken_rate_pct'), $h('on_time_pct'), $h('evaluations'), $h('avg_evaluation'), $h('attendance_rate_pct')],
                    'types' => ['text', 'number', 'number', 'number', 'number', 'percent', 'percent', 'number', 'score', 'percent'],
                    'rows' => $rows],
            ],
        ];
    }
}
