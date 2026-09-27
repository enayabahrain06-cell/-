<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum TajweedAspect: string
{
    use HasLabel;

    case Makharij = 'makharij';
    case Madd = 'madd';
    case Ghunnah = 'ghunnah';
    case Qalqalah = 'qalqalah';
    case Idgham = 'idgham';
}
