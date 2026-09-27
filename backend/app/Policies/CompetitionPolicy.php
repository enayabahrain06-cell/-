<?php

namespace App\Policies;

use App\Models\Competition;
use App\Models\User;
use App\Support\Track;

/** A competition belongs to one gender track; staff outside it cannot see or change it (Super Admin sees both). */
class CompetitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('competitions.view');
    }

    public function view(User $user, Competition $c): bool
    {
        return $user->can('competitions.view') && Track::allows($user, $c->gender);
    }

    public function create(User $user): bool
    {
        return $user->can('competitions.manage');
    }

    public function update(User $user, Competition $c): bool
    {
        return $user->can('competitions.manage') && Track::allows($user, $c->gender);
    }

    public function delete(User $user, Competition $c): bool
    {
        return $this->update($user, $c) && $c->status === 'draft';
    }

    /** Judging needs the permission, the track and (checked in the service) an assignment as judge. */
    public function judge(User $user, Competition $c): bool
    {
        return ($user->can('competitions.judge') || $user->can('competitions.manage')) && Track::allows($user, $c->gender);
    }
}
