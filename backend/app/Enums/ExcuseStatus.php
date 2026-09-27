<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ExcuseStatus: string
{
    use HasLabel;

    /** Received before attendance was taken: the attendance row was set to excused directly. */
    case Applied = 'applied';
    /** Received after attendance was taken: waits for the teacher (or a supervisor). */
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
