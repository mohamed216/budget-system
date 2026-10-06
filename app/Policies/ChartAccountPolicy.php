<?php

namespace App\Policies;

use App\Models\ChartAccount;
use App\Models\User;

class ChartAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, ChartAccount $record): bool
    {
        return $user->getKey() !== null && $record->user_id !== null
            && (string) $record->user_id === (string) $user->getKey();
    }

    public function update(User $user, ChartAccount $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, ChartAccount $record): bool
    {
        return $this->view($user, $record);
    }

    public function restore(User $user, ChartAccount $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, ChartAccount $record): bool
    {
        return false;
    }
}
