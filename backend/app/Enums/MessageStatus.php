<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum MessageStatus: string
{
    use HasLabel;

    /** Planned for a later time (reminders, or anything due inside quiet hours). */
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    /** A scheduled message withdrawn before sending (session time changed, cancelled or deleted). */
    case Cancelled = 'cancelled';
    /** Not needed any more when it fell due (confirmed, excused, circle rule, session already started). */
    case Skipped = 'skipped';
    /** Not sent: the recipient stopped notifications or the number is marked invalid. */
    case Suppressed = 'suppressed';

    /** Still waiting to go out. */
    public function isPending(): bool
    {
        return $this === self::Scheduled || $this === self::Queued;
    }
}
