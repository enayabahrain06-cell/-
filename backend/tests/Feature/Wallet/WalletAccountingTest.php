<?php

use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Models\WalletTransaction;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
    $this->admin = actingAsRole('super_admin');
    $this->student = Student::factory()->create();
    $this->package = Package::factory()->create(['price_fils' => 20000]);
    $this->wallets = app(WalletService::class);
});

it('keeps the balance equal to the sum of transactions through any sequence', function () {
    $w = $this->wallets;
    $s = $this->student;

    $w->createInvoice($s, $this->package, 20000, now()->addDays(7), 'رسوم الفصل الأول');
    $w->createInvoice($s, $this->package, 5000, now()->addDays(30), 'رسوم كتب');
    $w->recordPayment($s, 12000, PaymentMethod::Cash, ['notify' => false]);
    $w->adjust($s, -1500, 'رسوم تأخير');
    $w->adjust($s, 3000, 'منحة');
    $w->recordPayment($s, 20000, PaymentMethod::Benefit, ['reference' => 'BEN123', 'notify' => false]);
    $w->refund($s, 4000, PaymentMethod::Cash, 'استرداد فائض');

    $wallet = $w->ensure($s);
    expect($w->verify($wallet))->toBeTrue()
        ->and($wallet->fresh()->balance_fils)->toBe(-20000 - 5000 + 12000 - 1500 + 3000 + 20000 - 4000)
        ->and(WalletTransaction::where('wallet_id', $wallet->id)->count())->toBe(7);

    // balance_after on every row is consistent with the running sum
    $running = 0;
    foreach (WalletTransaction::where('wallet_id', $wallet->id)->orderBy('id')->get() as $tx) {
        $running += $tx->amount_fils;
        expect($tx->balance_after_fils)->toBe($running);
    }
});

it('settles invoices oldest-first and handles partial payments', function () {
    $w = $this->wallets;
    $s = $this->student;

    $old = $w->createInvoice($s, $this->package, 20000, now()->addDays(3), 'قديمة');
    $new = $w->createInvoice($s, null, 10000, now()->addDays(20), 'جديدة');

    $p1 = $w->recordPayment($s, 15000, PaymentMethod::Cash, ['notify' => false]);
    expect($old->fresh()->paid_fils)->toBe(15000)->and($old->fresh()->status->value)->toBe('partial')
        ->and($new->fresh()->paid_fils)->toBe(0)->and($new->fresh()->status->value)->toBe('open')
        ->and($p1->allocations)->toHaveCount(1);

    $p2 = $w->recordPayment($s, 12000, PaymentMethod::Card, ['notify' => false]);
    expect($old->fresh()->status->value)->toBe('paid')
        ->and($new->fresh()->paid_fils)->toBe(7000)->and($new->fresh()->status->value)->toBe('partial')
        ->and($p2->allocations->pluck('amount_fils')->all())->toBe([5000, 7000]);

    // overpayment keeps credit in the wallet, invoices fully paid
    $w->recordPayment($s, 5000, PaymentMethod::Cash, ['notify' => false]);
    expect($new->fresh()->status->value)->toBe('paid')
        ->and($w->ensure($s)->fresh()->balance_fils)->toBe(2000)
        ->and($w->outstandingFils($s))->toBe(0)
        ->and($w->verify($w->ensure($s)))->toBeTrue();
});

it('records a payment through the API, credits the wallet, writes audit, generates receipt and messages the guardian', function () {
    $this->wallets->createInvoice($this->student, $this->package, 20000, now()->addDays(7), 'رسوم');

    $res = $this->postJson('/api/payments', [
        'student_id' => $this->student->id,
        'amount' => '20.000',
        'method' => 'bank_transfer',
        'reference' => 'TRX-1',
        'note' => 'دفعة كاملة',
    ])->assertCreated();

    expect($res->json('data.amount_fils'))->toBe(20000)
        ->and($res->json('data.allocations.0.amount_fils'))->toBe(20000)
        ->and(AuditLog::where('action', 'payment.recorded')->exists())->toBeTrue()
        ->and(MessageLog::where('type', 'payment_receipt')->where('recipient_phone', $this->student->guardian_phone)->exists())->toBeTrue()
        ->and(\App\Models\Media::where('collection', 'receipt_pdf')->exists())->toBeTrue();

    $this->getJson("/api/payments/{$res->json('data.id')}/receipt.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->getJson("/api/students/{$this->student->id}/wallet")->assertOk()->assertJsonPath('balance_fils', 0)->assertJsonPath('is_due', false);
});

it('rejects non-positive payments and zero adjustments', function () {
    $this->postJson('/api/payments', ['student_id' => $this->student->id, 'amount_fils' => 0, 'method' => 'cash'])->assertStatus(422);
    $this->postJson('/api/payments', ['student_id' => $this->student->id, 'amount_fils' => -500, 'method' => 'cash'])->assertStatus(422);
    $this->postJson("/api/students/{$this->student->id}/wallet/adjust", ['amount_fils' => 0, 'note' => 'x'])->assertStatus(422);
});

it('requires a note for adjustments and writes an audit row', function () {
    $this->postJson("/api/students/{$this->student->id}/wallet/adjust", ['amount_fils' => -2000])->assertStatus(422)->assertJsonValidationErrors('note');

    $this->postJson("/api/students/{$this->student->id}/wallet/adjust", ['amount_fils' => -2000, 'note' => 'خصم أخوة'])
        ->assertOk()->assertJsonPath('balance_fils', -2000);

    $audit = AuditLog::where('action', 'wallet.adjusted')->first();
    expect($audit->new_values['note'])->toBe('خصم أخوة')->and($audit->user_id)->toBe($this->admin->id);
});

it('shows a due badge when the balance is negative', function () {
    $this->wallets->createInvoice($this->student, $this->package, 20000, now()->addDays(7), 'رسوم');
    $this->getJson("/api/students/{$this->student->id}/wallet")->assertOk()->assertJsonPath('is_due', true)->assertJsonPath('balance_fils', -20000);
});

it('refunds only from credit and links the refund to its transaction', function () {
    $this->postJson('/api/refunds', ['student_id' => $this->student->id, 'amount_fils' => 1000, 'method' => 'cash', 'note' => 'استرداد'])->assertStatus(422);

    $this->wallets->recordPayment($this->student, 5000, PaymentMethod::Cash, ['notify' => false]);
    $res = $this->postJson('/api/refunds', ['student_id' => $this->student->id, 'amount_fils' => 3000, 'method' => 'cash', 'note' => 'استرداد جزئي'])->assertCreated();

    $refund = \App\Models\Refund::find($res->json('refund.id'));
    expect($refund->transaction->type->value)->toBe('refund')
        ->and($refund->transaction->amount_fils)->toBe(-3000)
        ->and($this->wallets->ensure($this->student)->fresh()->balance_fils)->toBe(2000)
        ->and(AuditLog::where('action', 'wallet.refunded')->exists())->toBeTrue();
});

it('lets a guardian see the wallet read-only and blocks other guardians', function () {
    $guardian = \App\Models\User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->student->update(['guardian_user_id' => $guardian->id]);
    $this->wallets->createInvoice($this->student, $this->package, 20000, now()->addDays(7), 'رسوم');

    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/me/wallet')->assertOk()->assertJsonPath('students.0.balance_fils', -20000);
    $this->getJson("/api/students/{$this->student->id}/wallet")->assertOk();
    $this->postJson("/api/students/{$this->student->id}/wallet/adjust", ['amount_fils' => 20000, 'note' => 'hack'])->assertForbidden();

    $other = \App\Models\User::factory()->withoutPassword()->create();
    $other->assignRole('guardian');
    $this->actingAs($other, 'sanctum');
    $this->getJson("/api/students/{$this->student->id}/wallet")->assertForbidden();
});

it('sends due reminders before and after the due date and raises overdue alerts', function () {
    $w = $this->wallets;
    $w->createInvoice($this->student, $this->package, 20000, now()->addDays(3), 'قبل الاستحقاق');
    $overdue = $w->createInvoice($this->student, $this->package, 5000, now()->subDays(8), 'متأخرة');
    $w->createInvoice($this->student, $this->package, 5000, now()->addDays(20), 'بعيدة');

    $this->artisan('invoices:send-reminders')->assertSuccessful();

    expect(MessageLog::where('type', 'payment_due_reminder')->count())->toBe(2)
        ->and(Invoice::whereNotNull('reminder_before_sent_at')->count())->toBe(1)
        ->and($overdue->fresh()->reminder_after_sent_at)->not->toBeNull()
        ->and(\App\Models\Alert::where('type', 'invoice_overdue')->where('subject_id', $overdue->id)->exists())->toBeTrue();

    // idempotent
    $this->artisan('invoices:send-reminders')->assertSuccessful();
    expect(MessageLog::where('type', 'payment_due_reminder')->count())->toBe(2);
});
