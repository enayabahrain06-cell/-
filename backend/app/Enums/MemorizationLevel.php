<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MemorizationLevel: string
{
    use HasLabel;

    case None = 'none';
    case JuzAmma = 'juz_amma';
    case JuzTabarak = 'juz_tabarak';
    case FiveAjza = 'five_ajza';
    case TenAjza = 'ten_ajza';
    case FifteenAjza = 'fifteen_ajza';
    case TwentyAjza = 'twenty_ajza';
    case Hafiz = 'hafiz';
}
