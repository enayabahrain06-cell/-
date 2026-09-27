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

    public function accepts(Gender|string $gender): bool
    {
        $value = $gender instanceof Gender ? $gender->value : $gender;

        return $this === self::Shared || $this->value === $value;
    }
}
