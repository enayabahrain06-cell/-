<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PaymentMethod: string
{
    use HasLabel;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Benefit = 'benefit';
    case Card = 'card';
}
