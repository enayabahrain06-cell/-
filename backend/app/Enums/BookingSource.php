<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum BookingSource: string
{
    use HasLabel;

    case Manual = 'manual';
    case Exam = 'exam';
    case Event = 'event';
}
