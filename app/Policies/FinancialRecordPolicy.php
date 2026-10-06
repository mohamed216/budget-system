<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FinancialRecordPolicy
{
    public function view(User $user, Model $record): bool
    {
        return $record->user_id !== null && (string) $record->user_id === (string) $user->getKey();
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }
}
