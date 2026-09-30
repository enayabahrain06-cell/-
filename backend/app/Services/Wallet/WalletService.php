<?php

namespace App\Services\Wallet;

use App\Enums\InvoiceStatus;
use App\Enums\MediaCollection;
use App\Enums\MessageType;
use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Messaging\MessageService;
use App\Services\Pdf\PdfService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All money movements go through this class inside DB transactions with the wallet row locked.
 * Invariant: wallets.balance_fils == SUM(wallet_transactions.amount_fils).
 *   charge     -X  (invoice issued)
 *   payment    +X  (money in; always positive; settles open invoices oldest-first)
 *   refund     -X  (money out; recorded in `refunds`)
 *   adjustment ±X  (discount / scholarship / correction; note required; audited)
 * A negative balance means the student owes money ("due" badge).
 */
class WalletService
{
    public function __construct(
        private AuditLogger $audit,
        private MessageService $messages,
        private PdfService $pdf,
        private MediaService $media,
    ) {}

    public function ensure(Student $student): Wallet
    {
        return Wallet::firstOrCreate(['student_id' => $student->id], ['balance_fils' => 0]);
    }

    public function createInvoice(Student $student, ?Package $package, int $amountFils, CarbonInterface $dueDate, string $description, ?string $term = null, ?int $issuedBy = null): Invoice
    {
        if ($amountFils <= 0) {
            throw ValidationException::withMessages(['amount_fils' => __('wallet.errors.amount_positive')]);
        }

        return DB::transaction(function () use ($student, $package, $amountFils, $dueDate, $description, $term, $issuedBy) {
            $wallet = $this->lockedWallet($student);

            $invoice = Invoice::create([
                'invoice_no' => Invoice::nextNo(),
                'student_id' => $student->id,
                'package_id' => $package?->id,
                'description' => $description,
                'amount_fils' => $amountFils,
                'paid_fils' => 0,
                'due_date' => $dueDate->toDateString(),
                'status' => InvoiceStatus::Open,
                'term' => $term ?? $package?->term,
                // The package's term, otherwise the current one, so the invoice shows under the term selector.
                'academic_term_id' => $package?->academic_term_id ?? \App\Support\TermScope::defaultId(),
                'issued_by' => $issuedBy ?? auth()->id(),
            ]);

            $this->post($wallet, TransactionType::Charge, -$amountFils, [
                'invoice_id' => $invoice->id,
                'reference' => $invoice->invoice_no,
                'note' => $description,
            ]);

            return $invoice;
        });
    }

    public function cancelInvoice(Invoice $invoice, string $note): Invoice
    {
        return DB::transaction(function () use ($invoice, $note) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            if ($invoice->paid_fils > 0) {
                throw ValidationException::withMessages(['invoice' => __('wallet.errors.cancel_paid')]);
            }
            if ($invoice->status === InvoiceStatus::Cancelled) {
                return $invoice;
            }
            $wallet = $this->lockedWallet($invoice->student);
            // reverse the charge
            $this->post($wallet, TransactionType::Adjustment, $invoice->amount_fils, [
                'invoice_id' => $invoice->id, 'reference' => $invoice->invoice_no, 'note' => $note,
            ]);
            $invoice->update(['status' => InvoiceStatus::Cancelled]);
            $this->audit->record('invoice.cancelled', $invoice, ['status' => 'open'], ['status' => 'cancelled', 'note' => $note]);

            return $invoice;
        });
    }

    /**
     * @param  array{reference?: ?string, note?: ?string, paid_at?: ?CarbonInterface, received_by?: ?int, receipt_image?: ?UploadedFile, notify?: bool}  $opts
     */
    public function recordPayment(Student $student, int $amountFils, PaymentMethod $method, array $opts = []): Payment
    {
        if ($amountFils <= 0) {
            throw ValidationException::withMessages(['amount_fils' => __('wallet.errors.amount_positive')]);
        }

        $payment = DB::transaction(function () use ($student, $amountFils, $method, $opts) {
            $wallet = $this->lockedWallet($student);

            $payment = Payment::create([
                'receipt_no' => Payment::nextReceiptNo(),
                'student_id' => $student->id,
                'amount_fils' => $amountFils,
                'method' => $method,
                'reference' => $opts['reference'] ?? null,
                'note' => $opts['note'] ?? null,
                'received_by' => $opts['received_by'] ?? auth()->id(),
                'paid_at' => $opts['paid_at'] ?? now(),
            ]);

            $this->post($wallet, TransactionType::Payment, $amountFils, [
                'payment_id' => $payment->id,
                'reference' => $payment->receipt_no,
                'note' => $opts['note'] ?? null,
            ]);

            $this->settleOldestFirst($student, $payment, $amountFils);

            return $payment;
        });

        if (! empty($opts['receipt_image']) && $opts['receipt_image'] instanceof UploadedFile) {
            $this->media->storeUpload($payment, MediaCollection::ReceiptImage, $opts['receipt_image']);
        }

        $this->generateReceiptPdf($payment);

        $this->audit->record('payment.recorded', $payment, [], [
            'amount_fils' => $amountFils, 'method' => $method->value, 'student_id' => $student->id, 'reference' => $opts['reference'] ?? null,
        ]);

        if ($opts['notify'] ?? true) {
            $this->sendReceipt($payment);
        }

        return $payment->fresh(['allocations.invoice', 'transaction']);
    }

    /** Allocate a payment to open invoices, oldest due date first. */
    private function settleOldestFirst(Student $student, Payment $payment, int $amountFils): void
    {
        $remaining = $amountFils;

        $invoices = Invoice::where('student_id', $student->id)
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Partial->value])
            ->orderBy('due_date')->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }
            $alloc = min($remaining, $invoice->outstandingFils());
            if ($alloc <= 0) {
                continue;
            }

            InvoicePayment::create(['invoice_id' => $invoice->id, 'payment_id' => $payment->id, 'amount_fils' => $alloc]);
            $invoice->paid_fils += $alloc;
            $invoice->status = $invoice->paid_fils >= $invoice->amount_fils ? InvoiceStatus::Paid : InvoiceStatus::Partial;
            $invoice->save();

            $remaining -= $alloc;
        }
    }

    public function adjust(Student $student, int $signedFils, string $note, ?int $createdBy = null, ?string $reference = null): WalletTransaction
    {
        if ($signedFils === 0) {
            throw ValidationException::withMessages(['amount_fils' => __('wallet.errors.amount_nonzero')]);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('wallet.errors.note_required')]);
        }

        $tx = DB::transaction(function () use ($student, $signedFils, $note, $createdBy, $reference) {
            $wallet = $this->lockedWallet($student);

            return $this->post($wallet, TransactionType::Adjustment, $signedFils, [
                'note' => $note, 'reference' => $reference, 'created_by' => $createdBy ?? auth()->id(),
            ]);
        });

        $this->audit->record('wallet.adjusted', $tx, [], ['student_id' => $student->id, 'amount_fils' => $signedFils, 'note' => $note]);

        return $tx;
    }

    public function refund(Student $student, int $amountFils, PaymentMethod $method, string $note, ?string $reference = null, ?int $approvedBy = null, ?CarbonInterface $paidAt = null): Refund
    {
        if ($amountFils <= 0) {
            throw ValidationException::withMessages(['amount_fils' => __('wallet.errors.amount_positive')]);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => __('wallet.errors.note_required')]);
        }

        $refund = DB::transaction(function () use ($student, $amountFils, $method, $note, $reference, $approvedBy, $paidAt) {
            $wallet = $this->lockedWallet($student);
            if ($wallet->balance_fils < $amountFils) {
                throw ValidationException::withMessages(['amount_fils' => __('wallet.errors.insufficient_credit', ['balance' => Money::format($wallet->balance_fils)])]);
            }

            $refundNo = Refund::nextNo();
            $tx = $this->post($wallet, TransactionType::Refund, -$amountFils, [
                'note' => $note, 'reference' => $refundNo, 'created_by' => $approvedBy ?? auth()->id(),
            ]);

            return Refund::create([
                'refund_no' => $refundNo,
                'student_id' => $student->id,
                'wallet_transaction_id' => $tx->id,
                'amount_fils' => $amountFils,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'approved_by' => $approvedBy ?? auth()->id(),
                'paid_at' => $paidAt ?? now(),
            ]);
        });

        $this->audit->record('wallet.refunded', $refund, [], ['student_id' => $student->id, 'amount_fils' => $amountFils, 'method' => $method->value, 'note' => $note]);

        return $refund;
    }

    /** True when the ledger and the cached balance agree. */
    public function verify(Wallet $wallet): bool
    {
        return (int) $wallet->fresh()->balance_fils === (int) WalletTransaction::where('wallet_id', $wallet->id)->sum('amount_fils');
    }

    public function outstandingFils(Student $student): int
    {
        return (int) Invoice::where('student_id', $student->id)
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Partial->value])
            ->get()->sum(fn (Invoice $i) => $i->outstandingFils());
    }

    public function generateReceiptPdf(Payment $payment): void
    {
        $payment->loadMissing(['student', 'receiver', 'allocations.invoice']);
        $wallet = $this->ensure($payment->student);

        try {
            $bytes = $this->pdf->render('pdf.receipt', [
                'payment' => $payment,
                'student' => $payment->student,
                'allocations' => $payment->allocations,
                'balanceAfter' => $payment->transaction?->balance_after_fils ?? $wallet->balance_fils,
            ]);
            $this->media->storeContents($payment, MediaCollection::ReceiptPdf, $bytes, 'pdf', 'application/pdf', "receipt-{$payment->receipt_no}.pdf");
        } catch (\Throwable $e) {
            report($e); // a PDF failure must never roll back a recorded payment
        }
    }

    public function sendReceipt(Payment $payment): void
    {
        $student = $payment->student;
        $wallet = $this->ensure($student);
        $locale = $student->locale?->value ?? 'ar';
        $vars = [
            'amount' => Money::format($payment->amount_fils, $locale),
            'invoice_no' => $payment->receipt_no,
            'balance' => Money::format($wallet->balance_fils, $locale),
        ];

        foreach (array_unique(array_filter([$student->guardian_phone, $student->student_phone])) as $phone) {
            $this->messages->send($phone, MessageType::PaymentReceipt, $vars, $locale, $student);
        }

        $payment->update(['receipt_sent_at' => now()]);
    }

    private function lockedWallet(Student $student): Wallet
    {
        $this->ensure($student);

        return Wallet::where('student_id', $student->id)->lockForUpdate()->first();
    }

    private function post(Wallet $wallet, TransactionType $type, int $delta, array $extra = []): WalletTransaction
    {
        $wallet->balance_fils += $delta;
        $wallet->save();

        return WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount_fils' => $delta,
            'balance_after_fils' => $wallet->balance_fils,
            'reference' => $extra['reference'] ?? null,
            'invoice_id' => $extra['invoice_id'] ?? null,
            'payment_id' => $extra['payment_id'] ?? null,
            'note' => $extra['note'] ?? null,
            'created_by' => $extra['created_by'] ?? auth()->id(),
        ]);
    }
}
