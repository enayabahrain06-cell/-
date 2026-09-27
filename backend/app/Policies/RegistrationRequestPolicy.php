<?php

namespace App\Policies;

use App\Models\RegistrationRequest;
use App\Models\User;

class RegistrationRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('registrations.view');
    }

    public function view(User $user, RegistrationRequest $request): bool
    {
        return $user->can('registrations.view');
    }

    public function decide(User $user, ?RegistrationRequest $request = null): bool
    {
        return $user->can('registrations.manage');
    }
}
