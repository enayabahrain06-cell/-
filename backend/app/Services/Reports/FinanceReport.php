<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Support\Money;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finance report: collected per package and period, refunds, outstanding per student, payment methods.
 * Scoped to the viewer's gender track; optional gender and package filters. Grouping is done in PHP (portable).
 */
class FinanceReport
{
    /** @param  array{from?:string|null,to?:string|null,package_id?:int|null,gender?:string|null}  $f */
    public function build(User $user, array $f): array
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $locale = app()->getLocale();
        $from = ! empty($f['from']) ? Carbon::parse($f['from'], $tz)->startOfDay() : now($tz)->startOfMonth();
        $to = ! empty($f['to']) ? Carbon::parse($f['to'], $tz)->endOfDay() : now($tz)->endOfDay();
        $m = fn (int $fils) => Money::format($fils, $locale);

        $viaStudent = function (Builder $q) use ($user, $f) {
            Track::scopeVia($q, $user, 'student');
            if (! empty($f['gender'])) {
                $q->whereHas('student', fn ($s) => $s->where('gender', $f['gender']));
            }
        };

        $payments = Payment::with(['student:id,full_name,student_no,gender', 'allocations.invoice:id,package_id', 'allocations.invoice.package:id,name,name_ar,name_en'])
            ->whereBetween('paid_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->tap($viaStudent)
            ->when(! empty($f['package_id']), fn ($q) => $q->whereHas('allocations.invoice', fn ($i) => $i->where('package_id', $f['package_id'])))
            ->when($f['term'] ?? null, fn ($q, $term) => $q->whereHas('allocations.invoice', fn ($i) => TermScope::packages($i, $term)))
            ->get();

        $refunds = Refund::whereBetween('paid_at', [$from->copy()->utc(), $to->copy()->utc()])->tap($viaStudent)->get(['id', 'student_id', 'amount_fils', 'method', 'paid_at']);

        $invoices = Invoice::with('package:id,name,name_ar,name_en')->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->tap($viaStudent)
            ->when(! empty($f['package_id']), fn ($q) => $q->where('package_id', $f['package_id']))
            ->tap(fn ($q) => TermScope::packages($q, $f['term'] ?? null))
            ->get(['id', 'student_id', 'package_id', 'amount_fils', 'paid_fils', 'status', 'due_date']);

        $due = Wallet::with('student:id,full_name,student_no,gender,guardian_phone')->where('balance_fils', '<', 0)->tap($viaStudent)
            ->orderBy('balance_fils')->get();

        $collected = (int) $payments->sum('amount_fils');
        $refunded = (int) $refunds->sum('amount_fils');

        // Collected per package: payment allocations to that package's invoices; unallocated credit is its own row.
        $byPackage = [];
        foreach ($payments as $p) {
            $allocated = 0;
            foreach ($p->allocations as $a) {
                $pkg = $a->invoice?->package;
                $key = $pkg?->id ?? 0;
                $byPackage[$key] ??= ['name' => $pkg ? $pkg->localizedName($locale) : __('reports.finance.other_invoices'), 'collected' => 0];
                $byPackage[$key]['collected'] += $a->amount_fils;
                $allocated += $a->amount_fils;
            }
            if ($p->amount_fils > $allocated) {
                $byPackage[-1] ??= ['name' => __('reports.finance.credit'), 'collected' => 0];
                $byPackage[-1]['collected'] += $p->amount_fils - $allocated;
            }
        }
        foreach ($invoices->groupBy('package_id') as $pid => $group) {
            $key = $pid ?: 0;
            $byPackage[$key] ??= ['name' => $group->first()->package?->localizedName($locale) ?? __('reports.finance.other_invoices'), 'collected' => 0];
            $byPackage[$key]['invoiced'] = (int) $group->sum('amount_fils');
            $byPackage[$key]['outstanding'] = (int) $group->sum(fn ($i) => max(0, $i->amount_fils - $i->paid_fils));
        }
        uasort($byPackage, fn ($a, $b) => $b['collected'] <=> $a['collected']);

        $byMethod = $payments->groupBy(fn ($p) => $p->method->value)->map(fn ($g, $method) => [
            'method' => $method, 'label' => __("enums.payment_method.{$method}"), 'count' => $g->count(), 'amount' => (int) $g->sum('amount_fils'),
        ])->sortByDesc('amount')->values();

        $byMonth = [];
        foreach ($payments as $p) {
            $k = Carbon::parse($p->paid_at)->setTimezone($tz)->format('Y-m');
            $byMonth[$k]['collected'] = ($byMonth[$k]['collected'] ?? 0) + $p->amount_fils;
        }
        foreach ($refunds as $r) {
            $k = Carbon::parse($r->paid_at)->setTimezone($tz)->format('Y-m');
            $byMonth[$k]['refunded'] = ($byMonth[$k]['refunded'] ?? 0) + $r->amount_fils;
        }
        ksort($byMonth);

        $outstandingTotal = (int) -$due->sum('balance_fils');

        return [
            'title' => __('reports.finance.title'),
            'period' => __('reports.period', ['from' => $from->toDateString(), 'to' => $to->toDateString()]),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'package_id' => $f['package_id'] ?? null, 'gender' => $f['gender'] ?? null, 'track' => Track::genderFor($user)?->value ?? 'both'],
            'summary' => [
                [__('reports.finance.collected'), $m($collected)],
                [__('reports.finance.refunded'), $m($refunded)],
                [__('reports.finance.net'), $m($collected - $refunded)],
                [__('reports.finance.invoiced'), $m((int) $invoices->sum('amount_fils'))],
                [__('reports.finance.payments_count'), (string) $payments->count()],
                [__('reports.finance.students_due'), (string) $due->count()],
                [__('reports.finance.outstanding'), $m($outstandingTotal)],
            ],
            'sections' => [
                ['key' => 'by_package', 'title' => __('reports.finance.by_package'), 'headings' => [__('reports.finance.package'), __('reports.finance.collected'), __('reports.finance.invoiced'), __('reports.finance.outstanding')],
                    'rows' => array_values(array_map(fn ($r) => [$r['name'], $m($r['collected']), $m($r['invoiced'] ?? 0), $m($r['outstanding'] ?? 0)], $byPackage))],
                ['key' => 'by_method', 'title' => __('reports.finance.by_method'), 'headings' => [__('reports.finance.method'), __('reports.finance.count'), __('reports.finance.collected')],
                    'rows' => $byMethod->map(fn ($r) => [$r['label'], (string) $r['count'], $m($r['amount'])])->all()],
                ['key' => 'by_month', 'title' => __('reports.finance.by_month'), 'headings' => [__('reports.finance.month'), __('reports.finance.collected'), __('reports.finance.refunded')],
                    'rows' => collect($byMonth)->map(fn ($r, $k) => [$k, $m($r['collected'] ?? 0), $m($r['refunded'] ?? 0)])->values()->all()],
                ['key' => 'outstanding', 'title' => __('reports.finance.outstanding_students'), 'headings' => [__('reports.student_no'), __('reports.student'), __('reports.phone'), __('reports.finance.balance')],
                    'rows' => $due->take(500)->map(fn ($w) => [$w->student?->student_no, $w->student?->full_name, $w->student?->guardian_phone, $m($w->balance_fils)])->values()->all()],
            ],
            'data' => [
                'totals' => ['collected' => $collected, 'refunded' => $refunded, 'net' => $collected - $refunded, 'invoiced' => (int) $invoices->sum('amount_fils'), 'payments' => $payments->count(), 'students_due' => $due->count(), 'outstanding' => $outstandingTotal],
                'by_package' => array_values(array_map(fn ($r) => ['name' => $r['name'], 'collected' => $r['collected'], 'invoiced' => $r['invoiced'] ?? 0, 'outstanding' => $r['outstanding'] ?? 0], $byPackage)),
                'by_method' => $byMethod->all(),
                'by_month' => collect($byMonth)->map(fn ($r, $k) => ['period' => $k, 'collected' => $r['collected'] ?? 0, 'refunded' => $r['refunded'] ?? 0])->values()->all(),
                'outstanding' => $due->take(100)->map(fn ($w) => ['student_id' => $w->student_id, 'student_no' => $w->student?->student_no, 'full_name' => $w->student?->full_name, 'balance_fils' => $w->balance_fils])->values()->all(),
            ],
        ];
    }
}
