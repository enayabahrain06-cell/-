<?php

namespace App\Services\Reports;

use App\Enums\SessionStatus;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Attendance per circle, per student and per day. Rate = (present + late) / (all records − excused).
 * Sessions: non-cancelled sessions up to today in the period; "taken" = attendance recorded.
 */
class AttendanceReport
{
    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);

        $sessions = LessonSession::with('lesson:id,name,teacher_id', 'lesson.teacher:id,name')
            ->whereIn('lesson_id', $ctx->lessonIds())
            ->whereBetween('session_date', [$ctx->fromDate(), $ctx->toDate()])
            ->get(['id', 'lesson_id', 'session_date', 'status', 'attendance_taken_at']);
        $due = $sessions->filter(fn ($s) => $s->status !== SessionStatus::Cancelled && $s->session_date->toDateString() <= $ctx->dueUntil());

        $records = Attendance::with('student:id,student_no,full_name')
            ->whereIn('lesson_session_id', LessonSession::select('id')->whereIn('lesson_id', $ctx->lessonIds())
                ->whereBetween('session_date', [$ctx->fromDate(), $ctx->toDate()]))
            ->get(['id', 'lesson_session_id', 'student_id', 'status']);
        $sessionById = $sessions->keyBy('id');
        $overall = ReportContext::tally($records);

        $byLesson = $records->groupBy(fn ($a) => $sessionById[$a->lesson_session_id]?->lesson_id);
        $circleRows = $sessions->groupBy('lesson_id')->map(function (Collection $s, $lessonId) use ($byLesson, $due) {
            $lesson = $s->first()->lesson;
            $t = ReportContext::tally($byLesson->get($lessonId, collect()));
            $d = $due->where('lesson_id', $lessonId);

            return [$lesson?->name, $lesson?->teacher?->name, $d->count(), $d->whereNotNull('attendance_taken_at')->count(), $t['present'], $t['late'], $t['absent'], $t['excused'], $t['rate']];
        })->sortBy(fn ($r) => $r[8] ?? 101)->values()->all();

        $studentRows = $records->groupBy('student_id')->map(function (Collection $g) use ($sessionById, $ctx) {
            $t = ReportContext::tally($g);
            $circles = $g->map(fn ($a) => $sessionById[$a->lesson_session_id]?->lesson?->name)->filter()->unique()->implode($ctx->locale === 'ar' ? '، ' : ', ');

            return [$g->first()->student?->student_no, $g->first()->student?->full_name, $circles, $t['present'], $t['late'], $t['absent'], $t['excused'], $t['rate']];
        })->sortBy(fn ($r) => [$r[7] ?? 101, $r[1]])->values()->take(1000)->all();

        $byDate = $records->groupBy(fn ($a) => $sessionById[$a->lesson_session_id]?->session_date->toDateString());
        $days = [];
        $end = Carbon::parse($ctx->toDate());
        for ($d = Carbon::parse($ctx->fromDate()), $i = 0; $d->lte($end) && $i < 400; $d->addDay(), $i++) {
            $k = $d->toDateString();
            if (! $byDate->has($k)) {
                continue;
            }
            $t = ReportContext::tally($byDate[$k]);
            $days[] = ['date' => $k] + $t;
        }

        $h = fn (string $k) => __("reports.attendance.{$k}");

        return [
            'title' => __('reports.attendance.title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters(),
            'summary' => [
                [$h('rate'), $overall['rate'] === null ? '—' : $overall['rate'].'%'],
                [$h('sessions'), $due->count()],
                [$h('taken'), $due->whereNotNull('attendance_taken_at')->count()],
                [$h('present'), $overall['present']],
                [$h('late'), $overall['late']],
                [$h('absent'), $overall['absent']],
                [$h('excused'), $overall['excused']],
            ],
            'sections' => [
                ['key' => 'by_circle', 'title' => $h('by_circle'),
                    'headings' => [__('reports.circle'), __('reports.teacher'), $h('sessions'), $h('taken'), $h('present'), $h('late'), $h('absent'), $h('excused'), $h('rate_pct')],
                    'types' => ['text', 'text', 'number', 'number', 'number', 'number', 'number', 'number', 'percent'],
                    'rows' => $circleRows],
                ['key' => 'by_student', 'title' => $h('by_student'),
                    'headings' => [__('reports.student_no'), __('reports.student'), __('reports.circle'), $h('present'), $h('late'), $h('absent'), $h('excused'), $h('rate_pct')],
                    'types' => ['text', 'text', 'text', 'number', 'number', 'number', 'number', 'percent'],
                    'rows' => $studentRows],
                ['key' => 'by_day', 'title' => $h('by_day'),
                    'headings' => [__('reports.date'), $h('present'), $h('late'), $h('absent'), $h('excused'), $h('rate_pct')],
                    'types' => ['date', 'number', 'number', 'number', 'number', 'percent'],
                    'rows' => array_map(fn ($d) => [$d['date'], $d['present'], $d['late'], $d['absent'], $d['excused'], $d['rate']], $days)],
            ],
            'data' => ['by_day' => $days],
        ];
    }
}
