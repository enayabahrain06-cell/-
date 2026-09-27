<?php

namespace App\Services\Attendance;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\AttendanceStatus;
use App\Models\Alert;
use App\Models\Attendance;
use App\Models\LessonSession;
use App\Models\Student;
use App\Services\Messaging\AttendanceMessenger;

/**
 * N absences within D days (settings, default 3 in 30) raise one open supervisor alert per student
 * and send the repeated_absence message to the guardian (at most once per 14 days per student).
 */
class RepeatedAbsenceDetector
{
    public function __construct(private AttendanceMessenger $messenger) {}

    public function threshold(): int
    {
        return (int) setting('attendance.repeated_absence_count', config('ahl.attendance.repeated_absence_count', 3));
    }

    public function windowDays(): int
    {
        return (int) setting('attendance.repeated_absence_days', config('ahl.attendance.repeated_absence_days', 30));
    }

    public function absenceCount(int $studentId): int
    {
        $since = now()->setTimezone(config('ahl.display_timezone', 'Asia/Bahrain'))->subDays($this->windowDays())->toDateString();

        return Attendance::where('student_id', $studentId)
            ->where('status', AttendanceStatus::Absent->value)
            ->whereHas('session', fn ($q) => $q->where('session_date', '>=', $since))
            ->count();
    }

    public function check(int $studentId, ?LessonSession $session = null): ?Alert
    {
        $count = $this->absenceCount($studentId);
        if ($count < $this->threshold()) {
            return null;
        }

        $student = Student::find($studentId);
        if (! $student) {
            return null;
        }

        $this->messenger->sendRepeatedAbsence($student, $count, $session);

        return Alert::raise(
            AlertType::RepeatedAbsence,
            __('attendance.alert_repeated_title', ['name' => $student->full_name]),
            __('attendance.alert_repeated_body', ['count' => $count, 'days' => $this->windowDays()]),
            $student,
            AlertSeverity::Warning,
        );
    }
}
