<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum RecipientType: string
{
    use HasLabel;

    case Student = 'student';
    case Guardian = 'guardian';
    case User = 'user';
}
