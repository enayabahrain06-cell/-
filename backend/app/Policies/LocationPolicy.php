<?php

namespace App\Policies;

use App\Support\Track;
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
        return $this->viewAny($user) && $this->inTrack($user, $location);
    }

    public function create(User $user): bool
    {
        return $user->can('locations.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('locations.manage') && $this->inTrack($user, $location);
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can('locations.manage') && $this->inTrack($user, $location);
    }

    /** Shared halls are visible to both tracks; single-gender halls only to their own track. */
    private function inTrack(User $user, Location $location): bool
    {
        $g = $location->gender?->value ?? 'shared';

        return $g === 'shared' || Track::allows($user, $g);
    }
}
