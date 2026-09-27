<?php

namespace App\Observers;

use App\Models\Student;
use App\Models\User;

/**
 * Guardian phone lives on the guardian's user row. When it changes, every child's
 * students.guardian_phone (a synced copy used for filtering and messaging) follows.
 * A student login user's phone likewise syncs to students.student_phone.
 */
class UserObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged('phone')) {
            return;
        }

        Student::where('guardian_user_id', $user->id)->update(['guardian_phone' => $user->phone]);
        Student::where('user_id', $user->id)->update(['student_phone' => $user->phone]);
    }
}
