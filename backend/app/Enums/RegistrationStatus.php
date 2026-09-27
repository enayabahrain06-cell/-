<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * pending = submitted, awaiting a decision. The decision is enrolled (into a circle), waitlist (no seat in
 * any matching circle) or rejected. pending_lottery only when the supervisor explicitly takes the lottery path.
 */
enum RegistrationStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Enrolled = 'enrolled';
    case PendingLottery = 'pending_lottery';
    case Waitlist = 'waitlist';
    case Rejected = 'rejected';

    /** Statuses that hold a seat in the package. */
    public static function seated(): array
    {
        return [self::Enrolled->value, self::PendingLottery->value];
    }

    public function isDecided(): bool
    {
        return in_array($this, [self::Enrolled, self::PendingLottery], true);
    }
}
