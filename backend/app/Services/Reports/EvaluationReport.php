<?php

namespace App\Services\Reports;

use App\Enums\ProgressType;
use App\Models\Evaluation;
use App\Models\StudentProgress;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Evaluation averages (0–10 per criterion; overall = mean of the four) and new memorization (ayahs recorded as
 * memorized) per circle and per student in the period.
 */
class EvaluationReport
{
    private const CRITERIA = ['memorization', 'tajweed', 'revision', 'behavior'];

    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);

        $evals = Evaluation::with(['student:id,student_no,full_name', 'lesson:id,name,teacher_id', 'lesson.teacher:id,name'])
            ->quran()
            ->whereIn('lesson_id', $ctx->lessonIds())
            ->whereBetween('evaluated_on', [$ctx->fromDate(), $ctx->toDate()])
            ->get(['id', 'student_id', 'lesson_id', 'type', 'evaluated_on', ...self::CRITERIA]);

        $memorized = StudentProgress::with(['student:id,student_no,full_name', 'lesson:id,name'])
            ->where('type', ProgressType::Memorized->value)
            ->whereIn('lesson_id', $ctx->lessonIds())
            ->whereBetween('recorded_on', [$ctx->fromDate(), $ctx->toDate()])
            ->get(['id', 'student_id', 'lesson_id', 'ayah_count']);

        $overall = fn ($e) => array_sum(array_map(fn ($c) => $e->{$c}, self::CRITERIA)) / count(self::CRITERIA);
        $avgs = fn (Collection $g) => array_map(fn ($c) => ReportContext::avg($g->pluck($c)), self::CRITERIA);

        $lessonKeys = $evals->pluck('lesson_id')->merge($memorized->pluck('lesson_id'))->filter()->unique();
        $circleRows = $lessonKeys->map(function ($lessonId) use ($evals, $memorized, $avgs, $overall) {
            $g = $evals->where('lesson_id', $lessonId);
            $m = $memorized->where('lesson_id', $lessonId);
            $lesson = $g->first()?->lesson ?? $m->first()?->lesson;

            return [$lesson?->name, $lesson?->teacher?->name, $g->count(), ...$avgs($g), ReportContext::avg($g->map($overall)), (int) $m->sum('ayah_count'), $m->pluck('student_id')->unique()->count()];
        })->sortByDesc(fn ($r) => $r[7] ?? -1)->values()->all();

        $studentKeys = $evals->pluck('student_id')->merge($memorized->pluck('student_id'))->unique();
        $studentRows = $studentKeys->map(function ($sid) use ($evals, $memorized, $avgs, $overall) {
            $g = $evals->where('student_id', $sid);
            $m = $memorized->where('student_id', $sid);
            $student = $g->first()?->student ?? $m->first()?->student;
            $circle = ($g->first()?->lesson ?? $m->first()?->lesson)?->name;

            return [$student?->student_no, $student?->full_name, $circle, $g->count(), ...$avgs($g), ReportContext::avg($g->map($overall)), (int) $m->sum('ayah_count')];
        })->sortBy(fn ($r) => $r[8] ?? 11)->values()->take(1000)->all();

        $h = fn (string $k) => __("reports.evaluation.{$k}");
        $crit = array_map(fn ($c) => $h($c), self::CRITERIA);

        return [
            'title' => __('reports.evaluation.title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters(),
            'summary' => [
                [$h('count'), $evals->count()],
                [$h('overall'), ReportContext::avg($evals->map($overall)) ?? '—'],
                ...array_map(fn ($c, $label) => [$label, ReportContext::avg($evals->pluck($c)) ?? '—'], self::CRITERIA, $crit),
                [$h('new_ayahs'), (int) $memorized->sum('ayah_count')],
                [$h('students_memorizing'), $memorized->pluck('student_id')->unique()->count()],
            ],
            'sections' => [
                ['key' => 'by_circle', 'title' => $h('by_circle'),
                    'headings' => [__('reports.circle'), __('reports.teacher'), $h('count'), ...$crit, $h('overall'), $h('new_ayahs'), $h('students_memorizing')],
                    'types' => ['text', 'text', 'number', 'score', 'score', 'score', 'score', 'score', 'number', 'number'],
                    'rows' => $circleRows],
                ['key' => 'by_student', 'title' => $h('by_student'),
                    'headings' => [__('reports.student_no'), __('reports.student'), __('reports.circle'), $h('count'), ...$crit, $h('overall'), $h('new_ayahs')],
                    'types' => ['text', 'text', 'text', 'number', 'score', 'score', 'score', 'score', 'score', 'number'],
                    'rows' => $studentRows],
            ],
        ];
    }
}
