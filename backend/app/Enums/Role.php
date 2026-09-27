<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum Role: string
{
    use HasLabel;

    case SuperAdmin = 'super_admin';
    case Supervisor = 'supervisor';
    case Teacher = 'teacher';
    case Student = 'student';
    case Guardian = 'guardian';
}
