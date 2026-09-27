<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use App\Support\Track;

class LessonPolicy
{
    /**
     * Teachers without lessons.manage only see their own circles; managers only circles in their track.
     * Sessions, attendance and evaluations all authorise through this method.
     */
    public static function ownsOrManages(User $user, Lesson $lesson): bool
    {
        return ($user->can('lessons.manage') && Track::allows($user, $lesson->gender)) || $lesson->teacher_id === $user->id;
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
        return $this->manages($user, $lesson);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson);
    }

    public function enroll(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson);
    }

    /**
     * Add existing students to the circle: managers within their track, or teachers with
     * enrollment.quick in their own circles only (the same reach as quick enrollment).
     * Removing students stays with enroll() above.
     */
    public function addStudents(User $user, Lesson $lesson): bool
    {
        return ($user->can('lessons.manage') || $user->can('enrollment.quick')) && self::ownsOrManages($user, $lesson);
    }

    public function changeLocation(User $user, Lesson $lesson): bool
    {
        return $this->manages($user, $lesson);
    }

    private function manages(User $user, Lesson $lesson): bool
    {
        return $user->can('lessons.manage') && Track::allows($user, $lesson->gender);
    }
}
