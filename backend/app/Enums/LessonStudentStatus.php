<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum LessonStudentStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Left = 'left';
}
