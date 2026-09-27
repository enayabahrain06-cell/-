<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;

class CertificatePolicy
{
    public function view(User $user, Certificate $certificate): bool
    {
        if ($user->can('exams.view') || $user->can('evaluations.view') || $user->can('students.view')) {
            return true;
        }

        return $user->student()->whereKey($certificate->student_id)->exists()
            || $user->children()->whereKey($certificate->student_id)->exists();
    }
}
