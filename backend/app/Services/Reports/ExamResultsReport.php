<?php

namespace App\Services\Reports;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exams dated in the period: attempts, graded, average score (and % of total marks), pass rate; plus one row per graded
 * attempt. Exams follow the viewer's track; teachers without exams.manage see exams of their own circles.
 */
class ExamResultsReport
{
    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);
        $ownOnly = $ctx->teacherOnly() && ! $user->can('exams.manage');

        $exams = Exam::query()->forStudents()
            // Half-open range: the `date` cast can store a time part (SQLite), which BETWEEN on the last day would miss.
            ->where('exam_date', '>=', $ctx->fromDate())->where('exam_date', '<', $ctx->to->copy()->addDay()->toDateString())
            ->tap(fn (Builder $q) => Track::scope($q, $user))
            ->when($ownOnly || ! empty($f['lesson_id']) || ! empty($f['teacher_id']), fn ($q) => $q->whereIn('lesson_id', $ctx->lessonIds()))
            ->when(! empty($f['package_id']), fn ($q) => $q->where(fn ($w) => $w->where('package_id', $f['package_id'])
                ->orWhereIn('lesson_id', \App\Models\Lesson::where('package_id', $f['package_id'])->select('id'))))
            ->when(! empty($f['gender']), fn ($q) => $q->where('gender', $f['gender']))
            ->tap(fn (Builder $q) => \App\Support\TermScope::viaPackageOrLesson($q, $ctx->term(), 'exam_date'))
            ->orderBy('exam_date')
            ->get(['id', 'name', 'type', 'exam_date', 'total_marks', 'pass_mark', 'lesson_id', 'package_id', 'status']);

        $attempts = ExamAttempt::with('student:id,student_no,full_name')
            ->whereIn('exam_id', $exams->pluck('id')->all() ?: [0])
            ->get(['id', 'exam_id', 'student_id', 'status', 'total_score', 'passed', 'submitted_at'])
            ->groupBy('exam_id');

        $yes = __('reports.yes');
        $no = __('reports.no');
        $examRows = [];
        $studentRows = [];
        $allGraded = collect();
        foreach ($exams as $e) {
            $a = $attempts->get($e->id, collect());
            $graded = $a->whereNotNull('total_score');
            $allGraded = $allGraded->merge($graded);
            $avg = ReportContext::avg($graded->pluck('total_score'));
            $examRows[] = [
                $e->name, $e->exam_date?->toDateString(), ReportContext::label('exam_type', $e->type?->value), $e->total_marks,
                $a->count(), $graded->count(), $avg, $avg === null ? null : ReportContext::rate($avg, $e->total_marks),
                ReportContext::rate($graded->where('passed', true)->count(), $graded->count()),
            ];
            foreach ($graded->sortByDesc('total_score') as $at) {
                $studentRows[] = [
                    $e->name, $at->student?->student_no, $at->student?->full_name, $at->total_score, $e->total_marks,
                    ReportContext::rate($at->total_score, $e->total_marks), $at->passed ? $yes : $no,
                ];
            }
        }

        $h = fn (string $k) => __("reports.exams.{$k}");
        $passRate = ReportContext::rate($allGraded->where('passed', true)->count(), $allGraded->count());

        return [
            'title' => __('reports.exams.title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters(),
            'summary' => [
                [$h('exams'), $exams->count()],
                [$h('attempts'), $attempts->flatten(1)->count()],
                [$h('graded'), $allGraded->count()],
                [$h('pass_rate'), $passRate === null ? '—' : $passRate.'%'],
            ],
            'sections' => [
                ['key' => 'by_exam', 'title' => $h('by_exam'),
                    'headings' => [$h('exam'), __('reports.date'), $h('type'), $h('total_marks'), $h('attempts'), $h('graded'), $h('average'), $h('average_pct'), $h('pass_rate_pct')],
                    'types' => ['text', 'date', 'text', 'number', 'number', 'number', 'score', 'percent', 'percent'],
                    'rows' => $examRows],
                ['key' => 'results', 'title' => $h('results'),
                    'headings' => [$h('exam'), __('reports.student_no'), __('reports.student'), $h('score'), $h('total_marks'), $h('score_pct'), $h('passed')],
                    'types' => ['text', 'text', 'text', 'number', 'number', 'percent', 'text'],
                    'rows' => array_slice($studentRows, 0, 2000)],
            ],
        ];
    }
}
