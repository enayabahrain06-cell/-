<?php

namespace App\Services\Reports;

use App\Http\Controllers\Api\Progress\ProgressReportController;
use App\Services\Issues\IssueService;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * The memorization / difficulty / track-comparison reports already exist as JSON endpoints for other screens
 * (ProgressReportController). These adapters call them unchanged and reshape the result into the common report shape,
 * so the reports screen can show and export them like every other report.
 */
class ProgressReports
{
    public function __construct(private ProgressReportController $controller) {}

    private function period(array $f): string
    {
        $ctx = new ReportContext(request()->user(), $f);

        return $ctx->period();
    }

    public function juz(Request $request): array
    {
        $d = $this->controller->byJuz($request)->getData(true);
        $h = fn (string $k) => __("reports.juz.{$k}");

        return [
            'title' => __('reports.juz.title'),
            'period' => __('reports.juz.snapshot'),
            'filters' => $request->only(['lesson_id', 'package_id', 'gender']),
            'summary' => [[$h('students'), $d['total']], [$h('started'), $d['total'] - ($d['data'][0]['students'] ?? 0)]],
            'sections' => [
                ['key' => 'by_juz', 'title' => $h('by_juz'), 'headings' => [$h('juz'), $h('students'), $h('share_pct')],
                    'types' => ['text', 'number', 'percent'],
                    'rows' => array_map(fn ($r) => [$r['label'], $r['students'], ReportContext::rate($r['students'], $d['total'])], $d['data'])],
            ],
        ];
    }

    public function issues(Request $request): array
    {
        $d = $this->controller->categories($request)->getData(true);
        $h = fn (string $k) => __("reports.issues.{$k}");
        $top = fn (array $cats) => implode(app()->getLocale() === 'ar' ? '، ' : ', ', array_map(fn ($c) => "{$c['label']} ({$c['count']})", array_slice($cats, 0, 3)));

        return [
            'title' => __('reports.issues.title'),
            'period' => $request->filled('from') || $request->filled('to') ? $this->period($request->only(['from', 'to'])) : __('reports.issues.open_now'),
            'filters' => $request->only(['from', 'to', 'lesson_id', 'gender', 'status']),
            'summary' => [[$h('total'), $d['total']]],
            'sections' => [
                ['key' => 'overall', 'title' => $h('overall'), 'headings' => [$h('category'), $h('count')], 'types' => ['text', 'number'],
                    'rows' => array_map(fn ($c) => [$c['label'], $c['count']], $d['overall'])],
                ['key' => 'by_circle', 'title' => $h('by_circle'), 'headings' => [__('reports.circle'), __('reports.teacher'), $h('count'), $h('top')], 'types' => ['text', 'text', 'number', 'text'],
                    'rows' => array_map(fn ($r) => [$r['lesson'] ?? '—', $r['teacher'] ?? '—', $r['total'], $top($r['categories'])], $d['by_lesson'])],
                ['key' => 'by_teacher', 'title' => $h('by_teacher'), 'headings' => [__('reports.teacher'), $h('count'), $h('top')], 'types' => ['text', 'number', 'text'],
                    'rows' => array_map(fn ($r) => [$r['teacher'] ?? '—', $r['total'], $top($r['categories'])], $d['by_teacher'])],
            ],
        ];
    }

    public function highIssues(Request $request): array
    {
        $d = $this->controller->highSeverity($request, app(IssueService::class))->getData(true);
        $h = fn (string $k) => __("reports.issues.{$k}");

        return [
            'title' => __('reports.high_issues.title'),
            'period' => __('reports.issues.open_now'),
            'filters' => $request->only(['lesson_id', 'gender', 'category']),
            'summary' => [[$h('total'), $d['total']]],
            'sections' => [
                ['key' => 'issues', 'title' => __('reports.high_issues.title'),
                    'headings' => [__('reports.student_no'), __('reports.student'), __('reports.circle'), __('reports.teacher'), $h('category'), $h('description'), $h('opened'), $h('follow_up')],
                    'types' => ['text', 'text', 'text', 'text', 'text', 'text', 'date', 'date'],
                    'rows' => array_map(fn ($r) => [
                        $r['student']['student_no'] ?? null, $r['student']['full_name'] ?? null, $r['lesson'], $r['teacher'],
                        trim($r['category_label'].($r['subcategory_label'] ? ' — '.$r['subcategory_label'] : '')), $r['description'],
                        $r['opened_at'] ? substr($r['opened_at'], 0, 10) : null, $r['next_follow_up_date'],
                    ], $d['data'])],
            ],
        ];
    }

    public function issueTrend(Request $request): array
    {
        $d = $this->controller->resolvedMonthly($request)->getData(true);
        $h = fn (string $k) => __("reports.issue_trend.{$k}");

        return [
            'title' => __('reports.issue_trend.title'),
            'period' => __('reports.issue_trend.months', ['n' => count($d['data'])]),
            'filters' => $request->only(['months', 'lesson_id', 'gender']),
            'summary' => [[$h('opened'), array_sum(array_column($d['data'], 'opened'))], [$h('resolved'), array_sum(array_column($d['data'], 'resolved'))]],
            'sections' => [
                ['key' => 'by_month', 'title' => $h('by_month'), 'headings' => [$h('month'), $h('opened'), $h('resolved')], 'types' => ['month', 'number', 'number'],
                    'rows' => array_map(fn ($r) => [$r['period'], $r['opened'], $r['resolved']], $d['data'])],
            ],
        ];
    }

    public function tracks(Request $request): array
    {
        $d = $this->controller->compareTracks($request)->getData(true);
        [$boys, $girls] = $d['data'];
        $h = fn (string $k) => __("reports.tracks.{$k}");
        $metric = fn (string $k, string $type = 'number') => [$h($k), $boys[$k], $girls[$k], $type];
        $money = fn (int $fils) => Money::format($fils, app()->getLocale());

        $rows = [
            $metric('active_students'), $metric('active_circles'), $metric('open_packages'), $metric('avg_memorized_ayahs'),
            $metric('open_issues'), $metric('high_issues'), $metric('attendance_percent'),
            [$h('collected_fils'), $money($boys['collected_fils']), $money($girls['collected_fils']), 'text'],
        ];

        return [
            'title' => __('reports.tracks.title'),
            'period' => __('reports.period', ['from' => $d['from'], 'to' => $d['to']]),
            'filters' => ['from' => $d['from'], 'to' => $d['to']],
            'summary' => [],
            'sections' => [
                ['key' => 'compare', 'title' => __('reports.tracks.title'), 'headings' => [$h('metric'), $boys['label'], $girls['label']],
                    'types' => ['text', 'number', 'number'],
                    'rows' => array_map(fn ($r) => array_slice($r, 0, 3), $rows)],
            ],
        ];
    }
}
