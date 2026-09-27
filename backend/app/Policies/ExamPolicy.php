<?php

namespace App\Policies;

use App\Support\Track;
use App\Models\Exam;
use App\Models\User;
use App\Services\Exams\ExamService;

class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('exams.view');
    }

    public function view(User $user, Exam $exam): bool
    {
        if (! $user->can('exams.view')) {
            return false;
        }

        return ($user->can('exams.manage') && Track::allows($user, $exam->gender)) || app(ExamService::class)->teacherOwns($user, $exam);
    }

    public function create(User $user): bool
    {
        return $user->can('exams.manage');
    }

    public function update(User $user, Exam $exam): bool
    {
        return $user->can('exams.manage') && Track::allows($user, $exam->gender);
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $user->can('exams.manage') && Track::allows($user, $exam->gender);
    }

    /** Enter paper scores / grade recitation / upload sheets. */
    public function grade(User $user, Exam $exam): bool
    {
        if (! $user->can('exams.grade')) {
            return false;
        }

        return ($user->can('exams.manage') && Track::allows($user, $exam->gender)) || app(ExamService::class)->teacherOwns($user, $exam);
    }
}
