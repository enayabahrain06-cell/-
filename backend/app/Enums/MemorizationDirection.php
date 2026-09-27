<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Order in which a package memorizes the Quran. */
enum MemorizationDirection: string
{
    use HasLabel;

    case Forward = 'forward';   // Al-Fatiha → An-Nas
    case Backward = 'backward'; // An-Nas → Al-Fatiha (most halaqat)
}
