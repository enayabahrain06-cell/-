<?php

namespace App\Services\Auth;

use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Messaging\MessageService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private MessageService $messages) {}

    /** Issue a code for the phone, invalidating previous unused codes, and send it by WhatsApp. */
    public function issue(User $user): OtpCode
    {
        $cfg = config('ahl.otp');

        $recent = OtpCode::where('phone', $user->phone)->whereNull('used_at')->latest('id')->first();
        if ($recent && $recent->created_at->gt(now()->subSeconds($cfg['resend_cooldown_seconds']))) {
            throw ValidationException::withMessages(['phone' => __('api.otp.cooldown', ['seconds' => $cfg['resend_cooldown_seconds']])]);
        }

        OtpCode::where('phone', $user->phone)->whereNull('used_at')->update(['used_at' => now()]);

        $code = str_pad((string) random_int(0, (10 ** $cfg['length']) - 1), $cfg['length'], '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            'phone' => $user->phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes($cfg['expiry_minutes']),
        ]);

        $this->messages->send(
            phone: $user->phone,
            type: MessageType::Otp,
            vars: ['code' => $code, 'minutes' => $cfg['expiry_minutes']],
            locale: $user->locale?->value ?? 'ar',
            user: $user,
            recipientType: RecipientType::User,
            immediate: true,
        );

        if (app()->environment('local', 'testing')) {
            $otp->setAttribute('plain_code', $code); // never persisted; surfaced for local dev
        }

        return $otp;
    }

    /** Verify a code. Throws a ValidationException with the reason on failure. */
    public function verify(string $phone, string $code): OtpCode
    {
        $max = (int) config('ahl.otp.max_attempts');
        $otp = OtpCode::where('phone', $phone)->whereNull('used_at')->latest('id')->first();

        if (! $otp) {
            throw ValidationException::withMessages(['code' => __('api.otp.not_found')]);
        }
        if ($otp->isExpired()) {
            throw ValidationException::withMessages(['code' => __('api.otp.expired')]);
        }
        if ($otp->attempts >= $max) {
            throw ValidationException::withMessages(['code' => __('api.otp.too_many_attempts')]);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $left = $max - $otp->attempts;

            throw ValidationException::withMessages(['code' => $left > 0 ? __('api.otp.invalid', ['left' => $left]) : __('api.otp.too_many_attempts')]);
        }

        $otp->update(['used_at' => now()]);

        return $otp;
    }
}
