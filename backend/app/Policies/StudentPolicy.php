<?php

namespace App\Policies;

use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('students.view');
    }

    /** Staff with students.view; a teacher only for students enrolled in their circles; the student; the guardian. */
    public function view(User $user, Student $student): bool
    {
        if ($student->user_id === $user->id || $student->guardian_user_id === $user->id) {
            return true;
        }
        if (! $user->can('students.view')) {
            return false;
        }
        if ($user->hasRole('teacher') && ! $user->can('students.manage')) {
            return self::isTeacherOf($user, $student);
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('students.manage');
    }

    public function update(User $user, Student $student): bool
    {
        return $user->can('students.manage');
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->can('students.manage');
    }

    public static function isTeacherOf(User $user, Student $student): bool
    {
        return LessonStudent::where('student_id', $student->id)->where('status', 'active')
            ->whereIn('lesson_id', $user->lessons()->select('id'))->exists();
    }
}
