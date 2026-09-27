<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AttemptStatus: string
{
    use HasLabel;

    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Graded = 'graded';
    case Expired = 'expired';
}
