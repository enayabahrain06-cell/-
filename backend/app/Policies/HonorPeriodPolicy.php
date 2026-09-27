<?php

namespace App\Policies;

use App\Models\HonorPeriod;
use App\Models\User;
use App\Support\Track;

/** Each track has its own monthly board; boys and girls are never ranked together or shown to the other track's staff. */
class HonorPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('honor.view');
    }

    public function view(User $user, HonorPeriod $p): bool
    {
        return $user->can('honor.view') && Track::allows($user, $p->gender);
    }

    public function update(User $user, HonorPeriod $p): bool
    {
        return $user->can('honor.manage') && Track::allows($user, $p->gender);
    }
}
