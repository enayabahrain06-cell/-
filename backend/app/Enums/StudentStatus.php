<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum StudentStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Inactive = 'inactive';
    case Graduated = 'graduated';
    case Suspended = 'suspended';
}
