<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum QuestionType: string
{
    use HasLabel;

    case Mcq = 'mcq';
    case TrueFalse = 'true_false';
    case CompleteVerse = 'complete_verse';
    case OrderVerses = 'order_verses';
    case Recitation = 'recitation';
}
