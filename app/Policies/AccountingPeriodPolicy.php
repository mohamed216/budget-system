<?php

namespace App\Policies;

use App\Models\AccountingPeriod;
use App\Models\User;

class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, AccountingPeriod $record): bool
    {
        return $user->getKey() !== null && $record->user_id !== null
            && (string) $record->user_id === (string) $user->getKey();
    }

    public function update(User $user, AccountingPeriod $record): bool
    {
        return $this->view($user, $record) && $record->isOpen() && ! $record->wasEverClosed();
    }

    public function close(User $user, AccountingPeriod $record): bool
    {
        return $this->view($user, $record) && $record->isOpen();
    }

    public function reopen(User $user, AccountingPeriod $record): bool
    {
        return $this->view($user, $record) && $record->isClosed();
    }

    public function delete(User $user, AccountingPeriod $record): bool
    {
        return $this->update($user, $record);
    }

    public function restore(User $user, AccountingPeriod $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, AccountingPeriod $record): bool
    {
        return false;
    }
}
