<?php

namespace App\Services\Reports;

use App\Models\Attendance;
use App\Models\LessonSession;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Collection;

/** Students absent at least `attendance.repeated_absence_count` times in the period (excused absences do not count). */
class AbsenceReport
{
    public function __construct(private SettingsService $settings) {}

    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);
        $threshold = max(1, (int) ($f['min_absences'] ?? $this->settings->get('attendance.repeated_absence_count', 3)));

        $records = Attendance::with(['student:id,student_no,full_name,guardian_phone', 'session:id,lesson_id,session_date', 'session.lesson:id,name,teacher_id', 'session.lesson.teacher:id,name'])
            ->whereIn('status', ['absent', 'excused'])
            ->whereIn('lesson_session_id', LessonSession::select('id')->whereIn('lesson_id', $ctx->lessonIds())
                ->whereBetween('session_date', [$ctx->fromDate(), $ctx->toDate()]))
            ->get(['id', 'lesson_session_id', 'student_id', 'status']);
        $sep = $ctx->locale === 'ar' ? '، ' : ', ';

        $rows = $records->groupBy('student_id')->map(function (Collection $g) use ($sep) {
            $absent = $g->filter(fn ($a) => $a->status->value === 'absent');

            return [
                'absent' => $absent->count(),
                'row' => [
                    $g->first()->student?->student_no,
                    $g->first()->student?->full_name,
                    $g->map(fn ($a) => $a->session?->lesson?->name)->filter()->unique()->implode($sep),
                    $g->map(fn ($a) => $a->session?->lesson?->teacher?->name)->filter()->unique()->implode($sep),
                    $absent->count(),
                    $g->count() - $absent->count(),
                    $absent->map(fn ($a) => $a->session?->session_date?->toDateString())->filter()->max(),
                    $g->first()->student?->guardian_phone,
                ],
            ];
        })->filter(fn ($r) => $r['absent'] >= $threshold)->sortByDesc('absent')->pluck('row')->values();

        $h = fn (string $k) => __("reports.absence.{$k}");

        return [
            'title' => __('reports.absence.title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters() + ['min_absences' => $threshold],
            'summary' => [
                [$h('students'), $rows->count()],
                [$h('threshold'), $threshold],
                [$h('absences'), (int) $rows->sum(fn ($r) => $r[4])],
            ],
            'sections' => [
                ['key' => 'students', 'title' => $h('list'),
                    'headings' => [__('reports.student_no'), __('reports.student'), __('reports.circle'), __('reports.teacher'), $h('absences'), $h('excused'), $h('last_absence'), __('reports.phone')],
                    'types' => ['text', 'text', 'text', 'text', 'number', 'number', 'date', 'phone'],
                    'rows' => $rows->take(1000)->all()],
            ],
        ];
    }
}
