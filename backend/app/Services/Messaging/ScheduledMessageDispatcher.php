<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Models\MessageLog;

/** Releases planned messages (status "scheduled") whose time has come. Run every minute. */
class ScheduledMessageDispatcher
{
    public function __construct(private AttendanceMessenger $attendance) {}

    /** @return array<string, int> count per resulting status */
    public function dispatchDue(): array
    {
        $counts = [];

        MessageLog::with(['session.lesson', 'student.guardian'])
            ->where('status', MessageStatus::Scheduled->value)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->chunkById(200, function ($logs) use (&$counts) {
                foreach ($logs as $log) {
                    $status = $this->attendance->release($log);
                    $counts[$status->value] = ($counts[$status->value] ?? 0) + 1;
                }
            });

        return $counts;
    }
}
