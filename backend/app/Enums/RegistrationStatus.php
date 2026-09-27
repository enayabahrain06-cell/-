<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum RegistrationStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Waitlist = 'waitlist';
    case Rejected = 'rejected';
}
