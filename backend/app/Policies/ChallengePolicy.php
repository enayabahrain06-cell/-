<?php

namespace App\Policies;

use App\Models\Challenge;
use App\Models\User;
use App\Support\Track;

class ChallengePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('challenges.view');
    }

    public function view(User $user, Challenge $c): bool
    {
        return $user->can('challenges.view') && Track::allows($user, $c->gender);
    }

    public function create(User $user): bool
    {
        return $user->can('challenges.manage');
    }

    public function update(User $user, Challenge $c): bool
    {
        return $user->can('challenges.manage') && Track::allows($user, $c->gender);
    }

    public function delete(User $user, Challenge $c): bool
    {
        return $this->update($user, $c) && $c->participants()->doesntExist();
    }
}
