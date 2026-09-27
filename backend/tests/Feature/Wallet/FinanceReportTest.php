<?php

use App\Enums\PaymentMethod;
use App\Models\Package;
use App\Models\Student;
use App\Services\Wallet\WalletService;

beforeEach(function () {
    $this->w = app(WalletService::class);
    $this->boysPkg = Package::factory()->create(['gender' => 'male', 'name_ar' => 'باقة البنين', 'name' => 'باقة البنين']);
    $this->girlsPkg = Package::factory()->girls()->create(['name_ar' => 'باقة البنات', 'name' => 'باقة البنات']);
    $this->boy = Student::factory()->male()->create();
    $this->girl = Student::factory()->female()->create();
    $this->w->createInvoice($this->boy, $this->boysPkg, 20000, now()->addDays(7), 'رسوم');
    $this->w->createInvoice($this->girl, $this->girlsPkg, 30000, now()->addDays(7), 'رسوم');
    $this->w->recordPayment($this->boy, 25000, PaymentMethod::Cash, ['notify' => false]);   // 20.000 to the invoice + 5.000 credit
    $this->w->recordPayment($this->girl, 10000, PaymentMethod::Benefit, ['notify' => false]); // partial; 20.000 still due
    $this->w->refund($this->boy, 2000, PaymentMethod::Cash, 'استرداد فائض');
});

it('totals collections, refunds, per-package allocation, methods and outstanding balances', function () {
    actingAsRole('super_admin');
    $d = $this->getJson('/api/reports/finance')->assertOk()->json('data');

    expect($d['totals'])->toMatchArray(['collected' => 35000, 'refunded' => 2000, 'net' => 33000, 'invoiced' => 50000, 'payments' => 2, 'students_due' => 1, 'outstanding' => 20000]);
    $pkg = collect($d['by_package'])->keyBy('name');
    expect($pkg['باقة البنين']['collected'])->toBe(20000)
        ->and($pkg['باقة البنات'])->toMatchArray(['collected' => 10000, 'invoiced' => 30000, 'outstanding' => 20000])
        ->and(collect($d['by_method'])->pluck('amount', 'method')->all())->toBe(['cash' => 25000, 'benefit' => 10000])
        ->and($d['outstanding'][0]['student_id'])->toBe($this->girl->id);
});

it('scopes the report to the viewer track and exports Excel and PDF', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $d = $this->getJson('/api/reports/finance')->assertOk()->json('data');
    expect($d['totals'])->toMatchArray(['collected' => 25000, 'students_due' => 0])
        ->and(collect($d['by_package'])->pluck('name')->all())->not->toContain('باقة البنات');

    $this->get('/api/reports/finance?format=xlsx')->assertOk()->assertHeader('content-disposition', 'attachment; filename=finance-report.xlsx');
    $pdf = $this->get('/api/reports/finance?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    actingAsRole('teacher');
    $this->getJson('/api/reports/finance')->assertForbidden();
});
