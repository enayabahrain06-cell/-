<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum AlertSeverity: string
{
    use HasLabel;

    case Info = 'info';
    case Warning = 'warning';
    case Danger = 'danger';
}
