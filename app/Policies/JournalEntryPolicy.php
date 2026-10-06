<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalEntry $record): bool
    {
        return $user->getKey() !== null && $record->user_id !== null
            && (string) $record->user_id === (string) $user->getKey();
    }

    public function update(User $user, JournalEntry $record): bool
    {
        return $this->view($user, $record) && $record->isDraft();
    }

    public function delete(User $user, JournalEntry $record): bool
    {
        return $this->view($user, $record) && $record->isDraft();
    }

    public function post(User $user, JournalEntry $record): bool
    {
        return $this->view($user, $record) && $record->isDraft();
    }

    public function restore(User $user, JournalEntry $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, JournalEntry $record): bool
    {
        return false;
    }
}
