<?php

namespace App\Services\Reports;

use App\Models\Challenge;
use App\Models\Competition;
use App\Models\HonorRanking;
use App\Models\StudentBadge;
use App\Models\User;
use App\Support\Track;

/**
 * Sections 13-14 report: competition participation, published results, challenge completion and the monthly top
 * performers of the honor board. Everything follows the viewer's gender track (boys and girls never in one list
 * unless the viewer sees both).
 */
class EngagementReport
{
    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);
        $gender = $f['gender'] ?? null;
        $locale = app()->getLocale();
        $h = fn (string $k) => __("engagement.report.{$k}");
        $track = fn (string $g) => $g === 'female' ? __('engagement.report.girls') : __('engagement.report.boys');

        $competitions = Track::scope(Competition::query(), $user)
            ->when($gender, fn ($q) => $q->where('gender', $gender))
            ->where('starts_at', '<', $ctx->to->copy()->addDay()->startOfDay()->utc())
            ->where('ends_at', '>=', $ctx->from->copy()->startOfDay()->utc())
            ->with(['participants.student:id,student_no,full_name', 'participants.scores:id,participant_id'])
            ->orderBy('starts_at')->get();

        $participation = [];
        $results = [];
        foreach ($competitions as $c) {
            $active = $c->participants->where('status', '!=', 'withdrawn');
            $participation[] = [
                $c->name($locale), __("engagement.report.type.{$c->type}"), $track($c->gender), display_tz($c->starts_at)->toDateString(),
                $active->count(), $active->filter(fn ($p) => $p->scores->isNotEmpty())->count(),
                __("engagement.report.status.{$c->status}"), $c->results_published_at ? __('reports.yes') : __('reports.no'),
            ];
            if ($c->results_published_at) {
                foreach ($active->whereNotNull('final_rank')->sortBy('final_rank') as $p) {
                    $results[] = [$c->name($locale), $p->final_rank, $p->student?->student_no, $p->student?->full_name, $p->final_score_x100 === null ? null : round($p->final_score_x100 / 100, 2)];
                }
            }
        }

        $challenges = Track::scope(Challenge::query(), $user)
            ->when($gender, fn ($q) => $q->where('gender', $gender))
            ->where('starts_at', '<=', $ctx->toDate())->where('ends_at', '>=', $ctx->fromDate())
            ->withCount(['participants', 'participants as completed_count' => fn ($q) => $q->where('status', 'completed')])
            ->orderBy('starts_at')->get();
        $challengeRows = $challenges->map(fn (Challenge $c) => [
            $c->name($locale), __("engagement.report.goal.{$c->goal_type}"), $track($c->gender), $c->starts_at?->toDateString(), $c->ends_at?->toDateString(),
            $c->participants_count, $c->completed_count, ReportContext::rate($c->completed_count, $c->participants_count),
        ])->all();

        $months = [];
        for ($m = $ctx->from->copy()->startOfMonth(); $m->lte($ctx->to); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $top = HonorRanking::with(['student:id,student_no,full_name', 'lesson:id,name', 'period'])
            ->whereHas('period', fn ($q) => Track::scope($q->whereIn('period', $months), $user)->when($gender, fn ($w) => $w->where('gender', $gender)))
            ->where('rank_in_track', '<=', 10)->get()
            ->sortBy([fn ($a, $b) => strcmp($a->period->period, $b->period->period), fn ($a, $b) => [$a->period->gender, $a->rank_in_track] <=> [$b->period->gender, $b->rank_in_track]])
            ->map(fn (HonorRanking $r) => [$r->period->period, $track($r->period->gender), $r->rank_in_track, $r->student?->student_no, $r->student?->full_name, $r->lesson?->name, round($r->points_x100 / 100, 2)])
            ->values()->all();

        $badges = StudentBadge::whereBetween('awarded_at', [$ctx->from->copy()->startOfDay()->utc(), $ctx->to->copy()->endOfDay()->utc()])
            ->whereHas('student', fn ($q) => Track::scope($q, $user)->when($gender, fn ($w) => $w->where('gender', $gender)))->count();
        $joined = $challenges->sum('participants_count');
        $done = $challenges->sum('completed_count');

        return [
            'title' => $h('title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters(),
            'summary' => [
                [$h('competitions'), $competitions->count()],
                [$h('participants'), collect($participation)->sum(4)],
                [$h('challenges'), $challenges->count()],
                [$h('completion_rate'), ($r = ReportContext::rate($done, $joined)) === null ? '—' : $r.'%'],
                [$h('badges'), $badges],
            ],
            'sections' => [
                ['key' => 'participation', 'title' => $h('participation'),
                    'headings' => [$h('competition'), $h('type_h'), $h('track'), __('reports.date'), $h('participants'), $h('judged'), $h('status_h'), $h('published')],
                    'types' => ['text', 'text', 'text', 'date', 'number', 'number', 'text', 'text'],
                    'rows' => $participation],
                ['key' => 'results', 'title' => $h('results'),
                    'headings' => [$h('competition'), $h('rank'), __('reports.student_no'), __('reports.student'), $h('score')],
                    'types' => ['text', 'number', 'text', 'text', 'score'],
                    'rows' => $results],
                ['key' => 'challenges', 'title' => $h('challenge_completion'),
                    'headings' => [$h('challenge'), $h('goal_h'), $h('track'), $h('from'), $h('to'), $h('joined'), $h('completed'), $h('completion_rate')],
                    'types' => ['text', 'text', 'text', 'date', 'date', 'number', 'number', 'percent'],
                    'rows' => $challengeRows],
                ['key' => 'top', 'title' => $h('top_performers'),
                    'headings' => [$h('month'), $h('track'), $h('rank'), __('reports.student_no'), __('reports.student'), $h('circle'), $h('points')],
                    'types' => ['text', 'text', 'number', 'text', 'text', 'text', 'score'],
                    'rows' => $top],
            ],
        ];
    }
}
