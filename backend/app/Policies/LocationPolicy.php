<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('locations.view') || $user->can('lessons.view');
    }

    public function view(User $user, Location $location): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('locations.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('locations.manage');
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can('locations.manage');
    }
}
