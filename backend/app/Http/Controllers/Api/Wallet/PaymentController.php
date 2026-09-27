<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Enums\MediaCollection;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StorePaymentRequest;
use App\Http\Requests\Wallet\StoreRefundRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Student;
use App\Services\Media\MediaService;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * @group Wallet & payments
 */
class PaymentController extends Controller
{
    /** Payments list. Filters: student_id, method, from/to (paid_at), received_by. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Models\Wallet::class);

        $q = Payment::with(['student', 'receiver', 'allocations.invoice', 'media'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('received_by'), fn ($q) => $q->where('received_by', $request->integer('received_by')))
            ->when($request->filled('from'), fn ($q) => $q->where('paid_at', '>=', Carbon::parse($request->string('from'), config('ahl.display_timezone'))->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('paid_at', '<=', Carbon::parse($request->string('to'), config('ahl.display_timezone'))->endOfDay()->utc()))
            ->orderByDesc('paid_at')->orderByDesc('id');

        return PaymentResource::collection($q->paginate((int) $request->integer('per_page', 25)))->response();
    }

    /**
     * Record a payment. Credits the wallet, settles open invoices oldest-first, generates the
     * receipt PDF and sends it to the guardian by WhatsApp. Amount must be positive.
     */
    public function store(StorePaymentRequest $request, WalletService $wallets): JsonResponse
    {
        $student = Student::findOrFail($request->validated('student_id'));
        Gate::authorize('recordPayment', [\App\Models\Wallet::class, $student]);

        $payment = $wallets->recordPayment($student, (int) $request->validated('amount_fils'), PaymentMethod::from($request->validated('method')), [
            'reference' => $request->validated('reference'),
            'note' => $request->validated('note'),
            'paid_at' => $request->filled('paid_at') ? Carbon::parse($request->validated('paid_at'), config('ahl.display_timezone'))->utc() : now(),
            'received_by' => $request->user()->id,
            'receipt_image' => $request->file('receipt_image'),
            'notify' => $request->boolean('notify', true),
        ]);

        return (new PaymentResource($payment->load(['student', 'receiver', 'allocations.invoice', 'media'])))->response()->setStatusCode(201);
    }

    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', [\App\Models\Wallet::class, $payment->student]);

        return new PaymentResource($payment->load(['student', 'receiver', 'allocations.invoice', 'media', 'transaction']));
    }

    /** Stream the receipt PDF (regenerates it if missing). */
    public function receiptPdf(Payment $payment, WalletService $wallets, MediaService $media): Response
    {
        Gate::authorize('view', [\App\Models\Wallet::class, $payment->student]);

        $file = $payment->mediaIn(MediaCollection::ReceiptPdf);
        if (! $file || ! $media->exists($file)) {
            $wallets->generateReceiptPdf($payment);
            $file = $payment->fresh()->mediaIn(MediaCollection::ReceiptPdf);
        }
        abort_if(! $file, 404);

        return response($media->contents($file), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$payment->receipt_no.'.pdf"',
        ]);
    }

    /** Re-send the receipt message. */
    public function resendReceipt(Payment $payment, WalletService $wallets): JsonResponse
    {
        Gate::authorize('recordPayment', [\App\Models\Wallet::class, $payment->student]);
        $wallets->sendReceipt($payment);

        return response()->json(['message' => __('api.saved')]);
    }

    /** Refunds list. */
    public function refunds(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Models\Wallet::class);

        $q = Refund::with(['student', 'approver'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->orderByDesc('paid_at');

        return response()->json($q->paginate((int) $request->integer('per_page', 25))->through(fn (Refund $r) => [
            'id' => $r->id, 'refund_no' => $r->refund_no, 'student' => ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no],
            'amount_fils' => $r->amount_fils, 'method' => $r->method->value, 'reference' => $r->reference, 'note' => $r->note,
            'approved_by' => $r->approver?->name, 'paid_at' => display_tz($r->paid_at)?->toIso8601String(),
        ]));
    }

    /** Record money going out (requires credit balance). Note required; audited. */
    public function storeRefund(StoreRefundRequest $request, WalletService $wallets): JsonResponse
    {
        $student = Student::findOrFail($request->validated('student_id'));
        Gate::authorize('refund', [\App\Models\Wallet::class, $student]);

        $refund = $wallets->refund(
            $student,
            (int) $request->validated('amount_fils'),
            PaymentMethod::from($request->validated('method')),
            $request->validated('note'),
            $request->validated('reference'),
            $request->user()->id,
            $request->filled('paid_at') ? Carbon::parse($request->validated('paid_at'), config('ahl.display_timezone'))->utc() : now(),
        );

        return response()->json([
            'message' => __('api.saved'),
            'refund' => ['id' => $refund->id, 'refund_no' => $refund->refund_no, 'amount_fils' => $refund->amount_fils, 'balance_fils' => $refund->transaction->balance_after_fils],
        ], 201);
    }
}
