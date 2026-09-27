<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum IssueSeverity: string
{
    use HasLabel;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
