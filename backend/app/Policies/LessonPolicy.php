<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    /** Teachers without lessons.manage only see their own circles. */
    public static function ownsOrManages(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.manage') || $lesson->teacher_id === $user->id;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('lessons.view');
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.view') && self::ownsOrManages($user, $lesson);
    }

    public function create(User $user): bool
    {
        return $user->can('lessons.manage');
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.manage');
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.manage');
    }

    public function enroll(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.manage');
    }

    public function changeLocation(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.manage');
    }
}
