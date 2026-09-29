<?php

namespace Ahl\Certificates\Policies;

use Ahl\Certificates\Models\Certificate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Default rules on four abilities (give them to users through your permission system, e.g. spatie/laravel-permission):
 * certificates.view, certificates.issue, certificates.approve, certificates.templates.
 * A recipient that is the signed-in user sees their own approved certificates.
 * Extend this class (or write your own) and set certificates.policy.
 */
class CertificatePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'certificates.view');
    }

    public function view(Authenticatable $user, Certificate $certificate): bool
    {
        return $this->allows($user, 'certificates.view')
            || ($certificate->isApproved() && $this->isRecipient($user, $certificate->recipient));
    }

    /** Create drafts for a recipient. */
    public function issueFor(Authenticatable $user, Model $recipient): bool
    {
        return $this->allows($user, 'certificates.issue');
    }

    public function update(Authenticatable $user, Certificate $certificate): bool
    {
        return $certificate->isDraft() && $certificate->recipient !== null && $this->issueFor($user, $certificate->recipient);
    }

    public function delete(Authenticatable $user, Certificate $certificate): bool
    {
        return $this->update($user, $certificate);
    }

    public function approve(Authenticatable $user, Certificate $certificate): bool
    {
        return $this->allows($user, 'certificates.approve');
    }

    public function revoke(Authenticatable $user, Certificate $certificate): bool
    {
        return $this->approve($user, $certificate);
    }

    /** Notify the recipient again. */
    public function send(Authenticatable $user, Certificate $certificate): bool
    {
        return $certificate->isApproved() && ($this->approve($user, $certificate)
            || ($certificate->recipient !== null && $this->issueFor($user, $certificate->recipient)));
    }

    public function manageTemplates(Authenticatable $user): bool
    {
        return $this->allows($user, 'certificates.templates');
    }

    /** Open a recipient's certificate list. */
    public function viewRecipient(Authenticatable $user, Model $recipient): bool
    {
        return $this->allows($user, 'certificates.view') || $this->isRecipient($user, $recipient);
    }

    /** Staff view of a recipient's list: drafts included and actions available. Otherwise read-only, approved and revoked only. */
    public function manageRecipient(Authenticatable $user, Model $recipient): bool
    {
        return $this->allows($user, 'certificates.view');
    }

    protected function allows(Authenticatable $user, string $ability): bool
    {
        return method_exists($user, 'can') && $user->can($ability);
    }

    protected function isRecipient(Authenticatable $user, ?Model $recipient): bool
    {
        return $recipient !== null && $user instanceof Model && $recipient->is($user);
    }
}
