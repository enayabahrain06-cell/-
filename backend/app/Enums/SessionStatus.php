<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum SessionStatus: string
{
    use HasLabel;

    case Scheduled = 'scheduled';
    case Held = 'held';
    case Cancelled = 'cancelled';
}
