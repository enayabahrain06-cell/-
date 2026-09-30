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
     * Record evaluations of a subject (Quran when none is named) for a class: evaluations.record plus lessons.manage,
     * or teaching that subject there (TeacherScope with the subject; the class teacher always counts).
     */
    public function record(User $user, Lesson $lesson, ?int $subjectId = null): bool
    {
        return $user->can('evaluations.record') && LessonPolicy::ownsOrManages($user, $lesson, $subjectId ?? Subject::quranId());
    }

    public function update(User $user, Evaluation $evaluation): bool
    {
        return $this->record($user, $evaluation->lesson, $evaluation->subject_id);
    }

    public function delete(User $user, Evaluation $evaluation): bool
    {
        return $this->record($user, $evaluation->lesson, $evaluation->subject_id);
    }

    public function send(User $user, Evaluation $evaluation): bool
    {
        return $this->record($user, $evaluation->lesson, $evaluation->subject_id);
    }
}
