<?php

namespace App\Observers;

use App\Enums\SessionStatus;
use App\Models\LessonSession;
use App\Services\Messaging\AttendanceMessenger;

/**
 * Keeps attendance messaging in step with the session:
 * - date or start time changed → planned reminders are cancelled and planned again;
 * - status changed to cancelled → pending messages withdrawn and session_cancelled sent;
 * - deleted (schedule regenerated) → pending messages withdrawn.
 */
class LessonSessionObserver
{
    public function updated(LessonSession $session): void
    {
        $messenger = app(AttendanceMessenger::class);

        if ($session->wasChanged('status') && $session->status === SessionStatus::Cancelled) {
            $messenger->sessionCancelled($session);

            return;
        }

        if ($session->wasChanged(['session_date', 'start_time']) && $session->status === SessionStatus::Scheduled) {
            $messenger->reschedule($session);
        }
    }

    public function deleting(LessonSession $session): void
    {
        app(AttendanceMessenger::class)->cancelPending($session, 'session_deleted');
    }
}
