<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ExamType: string
{
    use HasLabel;

    case Paper = 'paper';
    case Online = 'online';
    /** Taken during public registration to recommend a memorization level; never shown to enrolled students. */
    case Placement = 'placement';
}
