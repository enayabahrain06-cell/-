<?php

use App\Enums\PaymentMethod;
use App\Models\Package;
use App\Models\Student;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('ahl.media.disk', 'local');
    actingAsRole('super_admin');
});

it('summarises the last 12 months of charges, payments and refunds for the wallet charts', function () {
    $tz = config('ahl.display_timezone', 'Asia/Bahrain');
    $this->travelTo(now($tz)->setDate(2026, 9, 15)->setTime(12, 0));
    $student = Student::factory()->create();
    $package = Package::factory()->create(['price_fils' => 20000]);
    $w = app(WalletService::class);

    // Older than the 12-month window: ignored.
    $this->travelTo(now($tz)->setDate(2025, 8, 20)->setTime(12, 0));
    $w->createInvoice($student, $package, 9000, now()->addDays(7), 'قديم');

    $this->travelTo(now($tz)->setDate(2026, 7, 3)->setTime(12, 0));
    $w->createInvoice($student, $package, 20000, now()->addDays(7), 'رسوم الفصل');
    $w->recordPayment($student, 5000, PaymentMethod::Cash, ['notify' => false]);

    $this->travelTo(now($tz)->setDate(2026, 9, 10)->setTime(12, 0));
    // Pays off everything (29000 charged in total) plus 2000 credit, which is then refunded.
    $w->recordPayment($student, 26000, PaymentMethod::Benefit, ['notify' => false]);
    $w->refund($student, 2000, PaymentMethod::Cash, 'استرداد');
    $w->adjust($student, 3000, 'منحة'); // adjustments are not charges, payments or refunds

    $this->travelTo(now($tz)->setDate(2026, 9, 15)->setTime(12, 0));
    $months = collect($this->getJson("/api/students/{$student->id}/wallet")->assertOk()->json('monthly'))->keyBy('month');

    expect($months)->toHaveCount(12)
        ->and($months->keys()->first())->toBe('2025-10')
        ->and($months->keys()->last())->toBe('2026-09')
        ->and($months['2026-07'])->toMatchArray(['charged_fils' => 20000, 'paid_fils' => 5000, 'refunded_fils' => 0])
        ->and($months['2026-09'])->toMatchArray(['charged_fils' => 0, 'paid_fils' => 26000, 'refunded_fils' => 2000])
        ->and($months['2026-08'])->toMatchArray(['charged_fils' => 0, 'paid_fils' => 0, 'refunded_fils' => 0])
        ->and($months->sum('charged_fils'))->toBe(20000);
});
