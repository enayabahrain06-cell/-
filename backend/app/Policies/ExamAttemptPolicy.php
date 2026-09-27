<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    public function view(User $user, ExamAttempt $attempt): bool
    {
        if ($user->can('exams.view')) {
            return $user->can('view', $attempt->exam);
        }

        return $this->ownsStudent($user, $attempt->student_id);
    }

    public function grade(User $user, ExamAttempt $attempt): bool
    {
        return $user->can('grade', $attempt->exam);
    }

    private function ownsStudent(User $user, int $studentId): bool
    {
        if ($user->student()->whereKey($studentId)->exists()) {
            return true;
        }

        return $user->children()->whereKey($studentId)->exists();
    }
}
