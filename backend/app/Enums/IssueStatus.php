<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum IssueStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Improving = 'improving';
    case Resolved = 'resolved';

    /** Statuses that still need follow-up. */
    public static function unresolved(): array
    {
        return [self::Open->value, self::Improving->value];
    }
}
