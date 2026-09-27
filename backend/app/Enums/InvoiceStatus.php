<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InvoiceStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
