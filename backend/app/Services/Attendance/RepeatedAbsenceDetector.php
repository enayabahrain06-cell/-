<?php

namespace App\Services\Attendance;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\AttendanceStatus;
use App\Models\Alert;
use App\Models\Attendance;
use App\Models\Student;

/** N absences within D days (settings) raise one open admin alert per student. */
class RepeatedAbsenceDetector
{
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
        $since = today()->subDays($this->windowDays())->toDateString();

        return Attendance::where('student_id', $studentId)
            ->where('status', AttendanceStatus::Absent->value)
            ->whereHas('session', fn ($q) => $q->where('session_date', '>=', $since))
            ->count();
    }

    public function check(int $studentId): ?Alert
    {
        $count = $this->absenceCount($studentId);
        if ($count < $this->threshold()) {
            return null;
        }

        $student = Student::find($studentId);
        if (! $student) {
            return null;
        }

        return Alert::raise(
            AlertType::RepeatedAbsence,
            __('attendance.alert_repeated_title', ['name' => $student->full_name]),
            __('attendance.alert_repeated_body', ['count' => $count, 'days' => $this->windowDays()]),
            $student,
            AlertSeverity::Warning,
        );
    }
}
