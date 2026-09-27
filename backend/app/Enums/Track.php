<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Staff data scope: the male track, the female track, or both. */
enum Track: string
{
    use HasLabel;

    case Male = 'male';
    case Female = 'female';
    case Both = 'both';
}
