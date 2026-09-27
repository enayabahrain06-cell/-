<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum EvaluationType: string
{
    use HasLabel;

    case Daily = 'daily';
    case Monthly = 'monthly';
}
