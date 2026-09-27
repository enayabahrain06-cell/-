<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/** Wallet, invoices, payments and refunds are all scoped by the student they belong to. */
class WalletPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('wallets.view');
    }

    /** Staff with wallets.view, the student's own login, or the guardian. */
    public function view(User $user, Student $student): bool
    {
        return $user->can('wallets.view')
            || $student->user_id === $user->id
            || $student->guardian_user_id === $user->id;
    }

    public function recordPayment(User $user, Student $student): bool
    {
        return $user->can('payments.record');
    }

    public function adjust(User $user, Student $student): bool
    {
        return $user->can('wallets.adjust');
    }

    public function refund(User $user, Student $student): bool
    {
        return $user->can('refunds.manage');
    }
}
