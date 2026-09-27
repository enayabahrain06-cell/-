<?php

namespace App\Jobs;

use App\Models\LessonSession;
use App\Services\Messaging\AttendanceMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** After attendance is saved: absence_notice to the guardian of every student marked absent (not late, not excused). */
class SendAbsenceMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $sessionId) {}

    public function handle(AttendanceMessenger $messenger): void
    {
        $session = LessonSession::with('lesson')->find($this->sessionId);
        if (! $session || ! $session->lesson) {
            return;
        }

        $messenger->sendAbsenceNotices($session);
    }
}
