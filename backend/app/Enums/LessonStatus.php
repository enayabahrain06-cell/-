<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum LessonStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
