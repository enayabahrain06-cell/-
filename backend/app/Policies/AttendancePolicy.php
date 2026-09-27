<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function view(User $user, Attendance $attendance): bool
    {
        return $user->can('attendance.view') && LessonPolicy::ownsOrManages($user, $attendance->session->lesson);
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $user->can('attendance.record') && LessonPolicy::ownsOrManages($user, $attendance->session->lesson);
    }
}
