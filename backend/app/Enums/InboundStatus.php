<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum InboundStatus: string
{
    use HasLabel;

    /** Handled automatically (confirmation, excuse, stop/start). */
    case Processed = 'processed';
    /** Free text waiting in the supervisor inbox. */
    case Open = 'open';
    case Resolved = 'resolved';
}
