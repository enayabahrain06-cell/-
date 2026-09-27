<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum PackageStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
