<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ProgressType: string
{
    use HasLabel;

    case Memorized = 'memorized';
    case Revised = 'revised';
}
