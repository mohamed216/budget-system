<?php

namespace App\Accounting\Actions;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteJournalDraft
{
    public function execute(User $actor, int $journalId): void
    {
        DB::transaction(function () use ($actor, $journalId) {
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if (! $journal->isDraft()) {
                throw new AccountingConflict('Posted journal cannot be deleted.');
            }
            $journal->lines()->lockForUpdate()->get();
            $journal->lines()->delete();
            $journal->delete();
        }, 3);
    }
}
