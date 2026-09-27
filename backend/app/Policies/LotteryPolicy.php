<?php

namespace App\Policies;

use App\Models\Lottery;
use App\Models\User;
use App\Support\Track;

/** Lotteries are managed by staff with lottery.manage within their gender track (mixed packages: both tracks). */
class LotteryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('lottery.view');
    }

    public function view(User $user, Lottery $lottery): bool
    {
        return $user->can('lottery.view') && $this->inTrack($user, $lottery);
    }

    public function create(User $user): bool
    {
        return $user->can('lottery.manage');
    }

    public function update(User $user, Lottery $lottery): bool
    {
        return $user->can('lottery.manage') && $this->inTrack($user, $lottery);
    }

    public function delete(User $user, Lottery $lottery): bool
    {
        return $this->update($user, $lottery);
    }

    private function inTrack(User $user, Lottery $lottery): bool
    {
        $g = $lottery->gender?->value;

        return $g === 'mixed' || Track::allows($user, $g);
    }
}
