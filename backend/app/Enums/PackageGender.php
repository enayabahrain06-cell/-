<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PackageGender: string
{
    use HasLabel;

    case Male = 'male';
    case Female = 'female';
    case Mixed = 'mixed';
}
