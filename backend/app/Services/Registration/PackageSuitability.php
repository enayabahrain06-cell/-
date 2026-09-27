<?php

namespace App\Services\Registration;

use App\Enums\Gender;
use App\Enums\PackageGender;
use App\Enums\PackageStatus;
use App\Models\Package;
use Carbon\CarbonInterface;

/**
 * Age is always computed server-side as of the package start date; the client's numbers are never trusted.
 */
class PackageSuitability
{
    public static function ageOn(CarbonInterface $birthDate, CarbonInterface $on): int
    {
        return (int) $birthDate->diffInYears($on, true);
    }

    /**
     * @return array{suitable: bool, reason: 'closed'|'age'|'gender'|null, age_at_start: int, is_full: bool, seats_left: int}
     */
    public static function check(Package $package, CarbonInterface $birthDate, Gender $gender): array
    {
        $age = self::ageOn($birthDate, $package->start_date);
        $reason = null;

        if ($package->status !== PackageStatus::Open) {
            $reason = 'closed';
        } elseif ($age < $package->min_age || $age > $package->max_age) {
            $reason = 'age';
        } elseif ($package->gender !== PackageGender::Mixed && $package->gender->value !== $gender->value) {
            $reason = 'gender';
        }

        $taken = $package->acceptedCount();

        return [
            'suitable' => $reason === null,
            'reason' => $reason,
            'age_at_start' => $age,
            'is_full' => $taken >= $package->seats,
            'seats_left' => max(0, $package->seats - $taken),
        ];
    }
}
