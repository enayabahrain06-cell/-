<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AttendanceStatus: string
{
    use HasLabel;

    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';
}
