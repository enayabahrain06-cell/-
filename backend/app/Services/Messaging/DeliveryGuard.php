<?php

namespace App\Services\Messaging;

use App\Enums\MessageType;
use App\Models\PhoneStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who must not receive a message: recipients who stopped notifications (users.notifications_enabled,
 * or an opted-out number with no user) and numbers marked invalid after N failed deliveries (reset by a confirmed delivery).
 */
class DeliveryGuard
{
    public function __construct(private MessagingRules $rules) {}

    /** @return 'invalid_number'|'notifications_disabled'|null */
    public function blockedReason(string $phone, MessageType $type, ?User $user = null): ?string
    {
        if (! $type->respectsOptOut()) {
            return null;
        }

        $status = PhoneStatus::where('phone', $phone)->first();
        if ($status?->invalid_at) {
            return 'invalid_number';
        }
        if ($status?->opted_out_at) {
            return 'notifications_disabled';
        }
        if ($user && $user->getAttribute('notifications_enabled') !== null && ! (bool) $user->getAttribute('notifications_enabled')) {
            return 'notifications_disabled';
        }
        if (User::where('phone', $phone)->where('notifications_enabled', false)->exists()) {
            return 'notifications_disabled';
        }

        return null;
    }

    public function recordFailure(string $phone, ?string $error = null): void
    {
        $status = PhoneStatus::firstOrCreate(['phone' => $phone]);
        $count = $status->failed_count + 1;
        $status->update([
            'failed_count' => $count,
            'last_error' => $error ? mb_substr($error, 0, 1000) : null,
            'invalid_at' => $status->invalid_at ?? ($count >= max(1, $this->rules->int('messaging.invalid_after_failures')) ? now() : null),
        ]);
    }

    /** A confirmed delivery (ack) resets the failure count and clears an invalid mark. */
    public function recordSuccess(string $phone): void
    {
        PhoneStatus::where('phone', $phone)
            ->where(fn ($q) => $q->where('failed_count', '>', 0)->orWhereNotNull('invalid_at'))
            ->update(['failed_count' => 0, 'invalid_at' => null, 'updated_at' => now()]);
    }

    /** "إيقاف / Stop": every user with this number, and the number itself (guardians without a login). */
    public function optOut(string $phone): void
    {
        DB::transaction(function () use ($phone) {
            User::where('phone', $phone)->update(['notifications_enabled' => false]);
            PhoneStatus::updateOrCreate(['phone' => $phone], ['opted_out_at' => now()]);
        });
    }

    /** "تشغيل / Start". */
    public function optIn(string $phone): void
    {
        DB::transaction(function () use ($phone) {
            User::where('phone', $phone)->update(['notifications_enabled' => true]);
            PhoneStatus::where('phone', $phone)->update(['opted_out_at' => null, 'updated_at' => now()]);
        });
    }
}
