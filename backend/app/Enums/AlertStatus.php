<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AlertStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Resolved = 'resolved';
}
