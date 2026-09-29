<?php

namespace App\Policies;

use Ahl\Certificates\Models\Certificate;
use Ahl\Certificates\Policies\CertificatePolicy as BasePolicy;
use App\Models\Student;
use App\Models\User;
use App\Support\Track;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff see certificates in their gender track (teachers: their own students). Drafts and revoked
 * certificates are staff-only; the student and guardian see approved ones read-only.
 */
class CertificatePolicy extends BasePolicy
{
    public function view(Authenticatable $user, Certificate $certificate): bool
    {
        $student = $this->student($certificate->recipient);
        if (self::isStaffFor($user, $student)) {
            return true;
        }

        return $certificate->isApproved() && $student && self::isFamily($user, $student->id);
    }

    /** Create drafts for a student (teachers: only students in their circles). */
    public function issueFor(Authenticatable $user, Model $recipient): bool
    {
        return $user->can('certificates.issue') && self::isStaffFor($user, $this->student($recipient));
    }

    public function approve(Authenticatable $user, Certificate $certificate): bool
    {
        $student = $this->student($certificate->recipient);

        return $user->can('certificates.approve') && $student && Track::allows($user, $student->gender);
    }

    public function viewRecipient(Authenticatable $user, Model $recipient): bool
    {
        return $recipient instanceof Student && $user->can('view', $recipient);
    }

    public function manageRecipient(Authenticatable $user, Model $recipient): bool
    {
        return self::isStaffFor($user, $this->student($recipient));
    }

    public static function isStaffFor(Authenticatable $user, ?Student $student): bool
    {
        /** @var User $user */
        if (! $student || ! Track::allows($user, $student->gender)) {
            return false;
        }
        if ($user->can('students.manage') || $user->can('certificates.approve')) {
            return $user->can('certificates.view') || $user->can('students.view');
        }

        // Teachers (and other limited staff): only students in their own circles.
        return ($user->can('certificates.view') || $user->can('students.view')) && StudentPolicy::isTeacherOf($user, $student);
    }

    public static function isFamily(Authenticatable $user, int $studentId): bool
    {
        /** @var User $user */
        return $user->student()->whereKey($studentId)->exists()
            || $user->children()->whereKey($studentId)->exists();
    }

    private function student(?Model $recipient): ?Student
    {
        return $recipient instanceof Student ? $recipient : null;
    }
}
