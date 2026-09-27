<?php

namespace App\Services\Reports;

use App\Models\MessageLog;
use App\Models\User;
use App\Support\Track;

/**
 * WhatsApp log for the period: totals per message type and status, and the failures with their error.
 * Track-limited staff see messages about students of their track only.
 */
class MessagesReport
{
    private const DONE = ['sent', 'delivered', 'read'];

    private const PENDING = ['scheduled', 'queued'];

    public function build(User $user, array $f): array
    {
        $ctx = new ReportContext($user, $f);

        $logs = MessageLog::query()
            ->whereBetween('created_at', [$ctx->from->copy()->utc(), $ctx->to->copy()->utc()])
            ->tap(fn ($q) => Track::scopeVia($q, $user, 'student'))
            ->when(! empty($f['gender']), fn ($q) => $q->whereHas('student', fn ($s) => $s->where('gender', $f['gender'])))
            ->get(['id', 'type', 'status', 'recipient_phone', 'student_id', 'attempts', 'error', 'created_at']);
        $status = fn ($m) => $m->status->value;

        $byType = $logs->groupBy(fn ($m) => $m->type->value)->map(fn ($g, $type) => [
            ReportContext::label('message_type', $type), $g->count(),
            $g->filter(fn ($m) => in_array($status($m), self::DONE, true))->count(),
            $g->filter(fn ($m) => $status($m) === 'failed')->count(),
            $g->filter(fn ($m) => in_array($status($m), self::PENDING, true))->count(),
            ReportContext::rate($g->filter(fn ($m) => in_array($status($m), self::DONE, true))->count(), $g->count()),
        ])->sortByDesc(fn ($r) => $r[1])->values()->all();

        $byStatus = $logs->countBy($status)->sortDesc()
            ->map(fn ($n, $s) => [ReportContext::label('message_status', $s), $n, ReportContext::rate($n, $logs->count())])->values()->all();

        $failures = $logs->filter(fn ($m) => $status($m) === 'failed')->sortByDesc('id')->take(500)
            ->map(fn ($m) => [display_tz($m->created_at)?->format('Y-m-d H:i'), $m->recipient_phone, ReportContext::label('message_type', $m->type->value), $m->attempts, $m->error])
            ->values()->all();

        $done = $logs->filter(fn ($m) => in_array($status($m), self::DONE, true))->count();
        $h = fn (string $k) => __("reports.messages.{$k}");

        return [
            'title' => __('reports.messages.title'),
            'period' => $ctx->period(),
            'filters' => $ctx->filters(),
            'summary' => [
                [$h('total'), $logs->count()],
                [$h('delivered'), $done],
                [$h('failed'), $logs->filter(fn ($m) => $status($m) === 'failed')->count()],
                [$h('success_rate'), ($r = ReportContext::rate($done, $logs->count())) === null ? '—' : $r.'%'],
            ],
            'sections' => [
                ['key' => 'by_type', 'title' => $h('by_type'),
                    'headings' => [$h('type'), $h('total'), $h('delivered'), $h('failed'), $h('pending'), $h('success_rate_pct')],
                    'types' => ['text', 'number', 'number', 'number', 'number', 'percent'],
                    'rows' => $byType],
                ['key' => 'by_status', 'title' => $h('by_status'),
                    'headings' => [$h('status'), $h('total'), $h('share_pct')],
                    'types' => ['text', 'number', 'percent'],
                    'rows' => $byStatus],
                ['key' => 'failures', 'title' => $h('failures'),
                    'headings' => [$h('time'), $h('phone'), $h('type'), $h('attempts'), $h('error')],
                    'types' => ['datetime', 'phone', 'text', 'number', 'text'],
                    'rows' => $failures],
            ],
        ];
    }
}
