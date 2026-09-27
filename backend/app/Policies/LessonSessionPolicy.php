<?php

namespace App\Policies;

use App\Models\LessonSession;
use App\Models\User;

class LessonSessionPolicy
{
    private function scoped(User $user, LessonSession $session): bool
    {
        return LessonPolicy::ownsOrManages($user, $session->lesson);
    }

    public function view(User $user, LessonSession $session): bool
    {
        return $user->can('lessons.view') && $this->scoped($user, $session);
    }

    public function update(User $user, LessonSession $session): bool
    {
        return ($user->can('lessons.manage') || $user->can('attendance.record')) && $this->scoped($user, $session);
    }

    public function viewAttendance(User $user, LessonSession $session): bool
    {
        return $user->can('attendance.view') && $this->scoped($user, $session);
    }

    public function recordAttendance(User $user, LessonSession $session): bool
    {
        return $user->can('attendance.record') && $this->scoped($user, $session);
    }
}
