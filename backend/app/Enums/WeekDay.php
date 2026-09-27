<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum WeekDay: string
{
    use HasLabel;

    case Saturday = 'sat';
    case Sunday = 'sun';
    case Monday = 'mon';
    case Tuesday = 'tue';
    case Wednesday = 'wed';
    case Thursday = 'thu';
    case Friday = 'fri';
}
