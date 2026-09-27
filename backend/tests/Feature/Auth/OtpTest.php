<?php

use App\Models\MessageLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->guardian = User::factory()->withoutPassword()->create(['phone' => '+97336000020', 'locale' => 'ar']);
    $this->guardian->assignRole('guardian');
});

it('issues a hashed 6-digit code that expires in 5 minutes and logs a WhatsApp message', function () {
    $res = $this->postJson('/api/auth/otp/request', ['phone' => '36000020'])->assertOk();

    $otp = OtpCode::where('phone', '+97336000020')->latest('id')->first();
    expect($otp)->not->toBeNull()
        ->and($otp->expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(5)
        ->and($otp->code_hash)->not->toBe($res->json('debug_code'))
        ->and(Hash::check($res->json('debug_code'), $otp->code_hash))->toBeTrue();

    $log = MessageLog::where('recipient_phone', '+97336000020')->first();
    expect($log->type->value)->toBe('otp')
        ->and($log->body)->toContain($res->json('debug_code'))
        ->and($log->status->value)->toBe('sent'); // queue is sync in tests, log provider
});

it('verifies the code and returns a token', function () {
    $code = $this->postJson('/api/auth/otp/request', ['phone' => '+97336000020'])->json('debug_code');

    $this->postJson('/api/auth/otp/verify', ['phone' => '+97336000020', 'code' => $code])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);

    expect(OtpCode::where('phone', '+97336000020')->whereNull('used_at')->exists())->toBeFalse();
});

it('locks the code after 3 wrong attempts', function () {
    $code = $this->postJson('/api/auth/otp/request', ['phone' => '+97336000020'])->json('debug_code');
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 1; $i <= 3; $i++) {
        $this->postJson('/api/auth/otp/verify', ['phone' => '+97336000020', 'code' => $wrong])->assertStatus(422);
    }

    // Even the correct code is now refused.
    $this->postJson('/api/auth/otp/verify', ['phone' => '+97336000020', 'code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', __('api.otp.too_many_attempts'));
});

it('rejects an expired code', function () {
    $code = $this->postJson('/api/auth/otp/request', ['phone' => '+97336000020'])->json('debug_code');
    OtpCode::query()->update(['expires_at' => now()->subMinute()]);

    $this->postJson('/api/auth/otp/verify', ['phone' => '+97336000020', 'code' => $code])
        ->assertStatus(422)
        ->assertJsonPath('errors.code.0', __('api.otp.expired'));
});

it('refuses unknown phones', function () {
    $this->postJson('/api/auth/otp/request', ['phone' => '+97339999999'])->assertStatus(422);
});

it('enforces the resend cooldown', function () {
    $this->postJson('/api/auth/otp/request', ['phone' => '+97336000020'])->assertOk();
    $this->postJson('/api/auth/otp/request', ['phone' => '+97336000020'])->assertStatus(422);
});
