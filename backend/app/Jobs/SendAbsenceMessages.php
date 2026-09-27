<?php

namespace App\Jobs;

use App\Enums\AttendanceStatus;
use App\Enums\MessageType;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Services\Lessons\StudentMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** After attendance is saved: one absence message per absent student (student + guardian phones), including the missed assignment. */
class SendAbsenceMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $sessionId) {}

    public function handle(StudentMessenger $messenger): void
    {
        $session = LessonSession::with('lesson')->find($this->sessionId);
        if (! $session || ! $session->lesson) {
            return;
        }

        $absences = Attendance::with('student')
            ->where('lesson_session_id', $session->id)
            ->where('status', AttendanceStatus::Absent->value)
            ->whereNull('absence_notified_at')
            ->get();

        foreach ($absences as $attendance) {
            $student = $attendance->student;
            if (! $student) {
                continue;
            }

            $assignment = $attendance->memorization_assignment
                ?: LessonStudent::where('lesson_id', $session->lesson_id)->where('student_id', $student->id)->value('current_memorization')
                ?: '—';

            $messenger->notify($student, MessageType::Absence, [
                'lesson' => $session->lesson->name,
                'date' => $session->session_date->toDateString(),
                'assignment' => $assignment,
            ]);

            $attendance->update(['absence_notified_at' => now()]);
        }
    }
}
