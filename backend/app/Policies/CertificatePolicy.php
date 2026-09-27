<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\Student;
use App\Models\User;
use App\Support\Track;

/**
 * Staff see certificates in their gender track (teachers: their own students). Drafts and revoked
 * certificates are staff-only; the student and guardian see approved ones read-only.
 */
class CertificatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('certificates.view');
    }

    public function view(User $user, Certificate $certificate): bool
    {
        if (self::isStaffFor($user, $certificate->student)) {
            return true;
        }

        return $certificate->isApproved() && self::isFamily($user, $certificate->student_id);
    }

    /** Create drafts for a student (teachers: only students in their circles). */
    public function issueFor(User $user, Student $student): bool
    {
        return $user->can('certificates.issue') && self::isStaffFor($user, $student);
    }

    public function update(User $user, Certificate $certificate): bool
    {
        return $certificate->isDraft() && $this->issueFor($user, $certificate->student);
    }

    public function delete(User $user, Certificate $certificate): bool
    {
        return $this->update($user, $certificate);
    }

    public function approve(User $user, Certificate $certificate): bool
    {
        return $user->can('certificates.approve') && Track::allows($user, $certificate->student->gender);
    }

    public function revoke(User $user, Certificate $certificate): bool
    {
        return $this->approve($user, $certificate);
    }

    /** Resend the WhatsApp congratulations. */
    public function send(User $user, Certificate $certificate): bool
    {
        return $certificate->isApproved() && ($this->approve($user, $certificate) || $this->issueFor($user, $certificate->student));
    }

    public function manageTemplates(User $user): bool
    {
        return $user->can('certificates.templates');
    }

    public static function isStaffFor(User $user, ?Student $student): bool
    {
        if (! $student || ! Track::allows($user, $student->gender)) {
            return false;
        }
        if ($user->can('students.manage') || $user->can('certificates.approve')) {
            return $user->can('certificates.view') || $user->can('students.view');
        }

        // Teachers (and other limited staff): only students in their own circles.
        return ($user->can('certificates.view') || $user->can('students.view')) && StudentPolicy::isTeacherOf($user, $student);
    }

    public static function isFamily(User $user, int $studentId): bool
    {
        return $user->student()->whereKey($studentId)->exists()
            || $user->children()->whereKey($studentId)->exists();
    }
}
