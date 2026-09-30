<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;

/**
 * Photo access rules, registered as gates 'view-photo' and 'update-photo' (MediaServiceProvider).
 * Kept separate from StudentPolicy so the two can evolve independently.
 */
class StudentPhotoPolicy
{
    /**
     * The student and their guardian; the Super Admin; otherwise staff of the SAME gender as the student
     * (female students' photos only to female staff, male to male), and then only within their track
     * (teachers: only students enrolled in their circles).
     */
    public function view(User $user, Student $student): bool
    {
        if ($this->isSelfOrGuardian($user, $student)) {
            return true;
        }
        if ($user->hasRole(\App\Enums\Role::SuperAdmin->value)) {
            return true;
        }
        if (\App\Support\Track::staffGender($user)?->value !== $student->gender?->value) {
            return false;
        }
        if (! \App\Support\Track::allows($user, $student->gender)) {
            return false;
        }

        if ($user->hasRole('teacher')) {
            return $this->teaches($user, $student);
        }

        return $user->can('students.view');
    }

    /** Staff with students.photo, or the guardian of that student. */
    public function update(User $user, Student $student): bool
    {
        return $user->can('students.photo') || (int) $student->guardian_user_id === (int) $user->id;
    }

    public function bulk(User $user): bool
    {
        return $user->can('students.photo');
    }

    public function isSelfOrGuardian(User $user, Student $student): bool
    {
        return (int) $student->user_id === (int) $user->id || (int) $student->guardian_user_id === (int) $user->id;
    }

    public function teaches(User $user, Student $student): bool
    {
        return LessonStudent::where('student_id', $student->id)
            ->where('status', 'active')
            ->whereIn('lesson_id', \App\Support\TeacherScope::lessonIds($user))
            ->exists();
    }
}
