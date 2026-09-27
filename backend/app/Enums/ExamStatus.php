<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ExamStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Graded = 'graded';
}
