<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum TransactionType: string
{
    use HasLabel;

    case Charge = 'charge';
    case Payment = 'payment';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
}
