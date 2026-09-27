<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Which track a hall serves. A shared hall hosts either gender, but never both at the same time. */
enum LocationGender: string
{
    use HasLabel;

    case Male = 'male';
    case Female = 'female';
    case Shared = 'shared';

    /** $gender is a group gender; mixed early-years groups use girls or shared halls. */
    public function accepts(Gender|PackageGender|string $gender): bool
    {
        $value = $gender instanceof \BackedEnum ? $gender->value : $gender;
        if ($value === PackageGender::Mixed->value) {
            $value = Gender::Female->value;
        }

        return $this === self::Shared || $this->value === $value;
    }
}
