<?php

namespace App\Policies;

use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\User;

class EvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('evaluations.view');
    }

    public function view(User $user, Evaluation $evaluation): bool
    {
        return $user->can('evaluations.view') && LessonPolicy::ownsOrManages($user, $evaluation->lesson, $evaluation->subject_id ?? Subject::quranId());
    }

    /**
     * Record evaluations for a class: evaluations.record plus lessons.manage, or teaching the subject there. Until
     * التقييمات (Phase 4) the four criteria are Quran's, so a subject teacher evaluates only where they teach Quran.
     */
    public function record(User $user, Lesson $lesson): bool
    {
        return $user->can('evaluations.record') && LessonPolicy::ownsOrManages($user, $lesson, Subject::quranId());
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
