<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum LotteryStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Run = 'run';
    case Approved = 'approved';
    case Cancelled = 'cancelled';
}
