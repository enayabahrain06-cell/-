<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\AdjustWalletRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\StudentSummaryResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Invoice;
use App\Models\Student;
use App\Services\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * @group Wallet & payments
 */
class WalletController extends Controller
{
    /** Wallet overview for one student: balance, outstanding invoices, transaction history, payments. */
    public function show(Request $request, Student $student, WalletService $wallets): JsonResponse
    {
        Gate::authorize('view', [\App\Models\Wallet::class, $student]);

        $wallet = $wallets->ensure($student);
        $student->setRelation('wallet', $wallet);

        return response()->json([
            'student' => new StudentSummaryResource($student),
            'balance_fils' => $wallet->balance_fils,
            'is_due' => $wallet->balance_fils < 0,
            'outstanding_fils' => $wallets->outstandingFils($student),
            'invoices' => InvoiceResource::collection($student->invoices()->orderByDesc('due_date')->orderByDesc('id')->get()),
            'transactions' => WalletTransactionResource::collection(
                $wallet->transactions()->with(['creator', 'invoice', 'payment'])->paginate((int) $request->integer('per_page', 25))
            )->response()->getData(true),
            'payments' => PaymentResource::collection($student->payments()->with(['receiver', 'allocations.invoice', 'media'])->orderByDesc('paid_at')->limit(50)->get()),
            'refunds' => $student->refunds()->with('approver')->orderByDesc('paid_at')->get()->map(fn ($r) => [
                'id' => $r->id, 'refund_no' => $r->refund_no, 'amount_fils' => $r->amount_fils, 'method' => $r->method->value,
                'reference' => $r->reference, 'note' => $r->note, 'approved_by' => $r->approver?->name,
                'paid_at' => display_tz($r->paid_at)?->toIso8601String(),
            ]),
            'monthly' => $this->monthly($wallet),
        ]);
    }

    /**
     * The last 12 months (oldest first, display timezone) of what was charged, paid and refunded, as positive
     * fils, for the wallet charts. Built from every transaction, not the paginated page, and grouped in PHP so
     * it behaves the same on SQLite, MySQL and PostgreSQL.
     *
     * @return list<array{month: string, charged_fils: int, paid_fils: int, refunded_fils: int}>
     */
    private function monthly(\App\Models\Wallet $wallet): array
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $first = now($tz)->startOfMonth()->subMonths(11);
        $months = [];
        for ($m = $first->copy(); $m <= now($tz); $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'charged_fils' => 0, 'paid_fils' => 0, 'refunded_fils' => 0];
        }

        $wallet->transactions()->where('created_at', '>=', $first->copy()->utc())
            ->whereIn('type', [TransactionType::Charge->value, TransactionType::Payment->value, TransactionType::Refund->value])
            ->get(['type', 'amount_fils', 'created_at'])
            ->each(function ($tx) use (&$months, $tz) {
                $key = $tx->created_at->copy()->setTimezone($tz)->format('Y-m');
                if (! isset($months[$key])) {
                    return;
                }
                $field = match ($tx->type) {
                    TransactionType::Charge => 'charged_fils',
                    TransactionType::Payment => 'paid_fils',
                    default => 'refunded_fils',
                };
                $months[$key][$field] += abs((int) $tx->amount_fils);
            });

        return array_values($months);
    }

    /** Manual adjustment (discount, scholarship, correction). Signed amount in fils; note required; audited. */
    public function adjust(AdjustWalletRequest $request, Student $student, WalletService $wallets): JsonResponse
    {
        $tx = $wallets->adjust($student, (int) $request->validated('amount_fils'), $request->validated('note'), $request->user()->id, $request->validated('reference'));

        return response()->json([
            'message' => __('api.saved'),
            'transaction' => new WalletTransactionResource($tx->load('creator')),
            'balance_fils' => $tx->balance_after_fils,
        ]);
    }

    /** The authenticated student's wallet, or all children for a guardian. Read-only. */
    public function me(Request $request, WalletService $wallets): JsonResponse
    {
        $user = $request->user()->load(['student', 'children']);
        $students = collect([$user->student])->filter()->merge($user->children);

        return response()->json([
            'students' => $students->map(function (Student $s) use ($wallets) {
                $w = $wallets->ensure($s);
                $s->setRelation('wallet', $w);

                return [
                    'student' => new StudentSummaryResource($s),
                    'balance_fils' => $w->balance_fils,
                    'is_due' => $w->balance_fils < 0,
                    'outstanding_fils' => $wallets->outstandingFils($s),
                    'invoices' => InvoiceResource::collection($s->invoices()->orderByDesc('due_date')->get()),
                    'transactions' => WalletTransactionResource::collection($w->transactions()->with(['invoice', 'payment'])->limit(100)->get()),
                    'payments' => PaymentResource::collection($s->payments()->with(['allocations.invoice', 'media'])->orderByDesc('paid_at')->get()),
                ];
            })->values(),
        ]);
    }

    /** Invoices list (admin). Filters: status, student_id, package_id, overdue=1, from/to (due_date). */
    public function invoices(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Models\Wallet::class);

        $q = Invoice::with(['student', 'package'])
            ->tap(fn ($q) => \App\Support\Track::scopeVia($q, $request->user(), 'student'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('package_id'), fn ($q) => $q->where('package_id', $request->integer('package_id')))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', ['open', 'partial'])->whereDate('due_date', '<', now()->toDateString()))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('due_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('due_date', '<=', $request->date('to')))
            ->orderBy('due_date')->orderBy('id');

        return InvoiceResource::collection($q->paginate((int) $request->integer('per_page', 25)))->response();
    }

    /** Create a manual invoice for a student. */
    public function storeInvoice(Request $request, WalletService $wallets): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'amount_fils' => ['required', 'integer', 'min:1'],
            'due_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'term' => ['nullable', 'string', 'max:60'],
        ]);
        $student = Student::findOrFail($data['student_id']);
        Gate::authorize('recordPayment', [\App\Models\Wallet::class, $student]);

        $invoice = $wallets->createInvoice(
            $student,
            isset($data['package_id']) ? \App\Models\Package::find($data['package_id']) : null,
            (int) $data['amount_fils'],
            \Carbon\Carbon::parse($data['due_date']),
            $data['description'],
            $data['term'] ?? null,
            $request->user()->id,
        );

        return (new InvoiceResource($invoice->load(['student', 'package'])))->response()->setStatusCode(201);
    }

    public function cancelInvoice(Request $request, Invoice $invoice, WalletService $wallets): InvoiceResource
    {
        Gate::authorize('adjust', [\App\Models\Wallet::class, $invoice->student]);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return new InvoiceResource($wallets->cancelInvoice($invoice, $data['note'])->load(['student', 'package']));
    }
}
