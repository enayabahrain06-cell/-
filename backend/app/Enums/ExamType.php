<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ExamType: string
{
    use HasLabel;

    case Paper = 'paper';
    case Online = 'online';
}
