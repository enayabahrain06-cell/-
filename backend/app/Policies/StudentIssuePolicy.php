<?php

namespace App\Policies;

use App\Support\Track;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\User;

/** Guardians and students can read their own issues (via StudentPolicy::view) but never write. */
class StudentIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('evaluations.view');
    }

    public function view(User $user, StudentIssue $issue): bool
    {
        return app(StudentPolicy::class)->view($user, $issue->student);
    }

    public function create(User $user, Student $student): bool
    {
        return self::canWriteFor($user, $student);
    }

    public function update(User $user, StudentIssue $issue): bool
    {
        return self::canWriteFor($user, $issue->student);
    }

    public function addNote(User $user, StudentIssue $issue): bool
    {
        return self::canWriteFor($user, $issue->student);
    }

    /** Staff with evaluations.record: supervisors for anyone, teachers only for students in their circles. */
    public static function canWriteFor(User $user, Student $student): bool
    {
        if (! $user->can('evaluations.record')) {
            return false;
        }
        if ($user->can('students.manage') || $user->can('lessons.manage')) {
            return Track::allows($user, $student->gender);
        }

        return StudentPolicy::isTeacherOf($user, $student);
    }
}
