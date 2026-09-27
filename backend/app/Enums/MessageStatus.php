<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MessageStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
}
