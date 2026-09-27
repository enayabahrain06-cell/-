<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InboundIntent: string
{
    use HasLabel;

    case Confirm = 'confirm';
    case Excuse = 'excuse';
    case Stop = 'stop';
    case Start = 'start';
    case Other = 'other';
}
