<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Gender of a group (package, and the circles, lotteries, exams and bookings under it).
 * Male and female are the two separated tracks. Mixed is the early-years exception only:
 * children aged up to EARLY_YEARS_MAX_AGE, taught by a female teacher in a girls or shared hall,
 * visible to the supervisors of both tracks.
 */
enum PackageGender: string
{
    use HasLabel;

    case Male = 'male';
    case Female = 'female';
    case Mixed = 'mixed';

    public const EARLY_YEARS_MAX_AGE = 6;

    /** Whether a student of this gender may join the group. */
    public function accepts(Gender|string $student): bool
    {
        $value = $student instanceof Gender ? $student->value : $student;

        return $this === self::Mixed || $this->value === $value;
    }

    /** The gender a teacher (and hall) must serve for this group. */
    public function staffGender(): Gender
    {
        return $this === self::Male ? Gender::Male : Gender::Female;
    }
}
