<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteJournalDraft
{
    public function execute(User $actor, int $journalId): void
    {
        DB::transaction(function () use ($actor, $journalId) {
            AccountingPeriodLocks::owner($actor);
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if (! $journal->isDraft()) {
                throw new AccountingConflict('Posted journal cannot be deleted.');
            }
            $journal->lines()->lockForUpdate()->get();
            $allocations = DB::table('journal_line_allocations')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId);
            $allocations->orderBy('id')->lockForUpdate()->get();
            $allocations->delete();
            $journal->lines()->delete();
            $journal->delete();
        }, 3);
    }
}
