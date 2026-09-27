<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\User;

class EvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('evaluations.view');
    }

    public function view(User $user, Evaluation $evaluation): bool
    {
        return $user->can('evaluations.view') && LessonPolicy::ownsOrManages($user, $evaluation->lesson);
    }

    /** Record evaluations for a circle: evaluations.record plus ownership (teachers) or lessons.manage. */
    public function record(User $user, Lesson $lesson): bool
    {
        return $user->can('evaluations.record') && LessonPolicy::ownsOrManages($user, $lesson);
    }

    public function update(User $user, Evaluation $evaluation): bool
    {
        return $this->record($user, $evaluation->lesson);
    }

    public function delete(User $user, Evaluation $evaluation): bool
    {
        return $this->record($user, $evaluation->lesson);
    }

    public function send(User $user, Evaluation $evaluation): bool
    {
        return $this->record($user, $evaluation->lesson);
    }
}
