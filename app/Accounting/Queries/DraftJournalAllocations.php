<?php

namespace App\Accounting\Queries;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DraftJournalAllocations
{
    /** @return list<array{debit_line_index:int, credit_line_index:int, amount:string, category:?string}> */
    public function forJournal(User $actor, JournalEntry $journal): array
    {
        $lineIndexes = $journal->lines->values()->mapWithKeys(
            fn ($line, $index) => [$line->id => $index]
        );
        $rows = DB::table('journal_line_allocations')->where('user_id', $actor->id)
            ->where('journal_entry_id', $journal->id)->orderBy('id')->get();

        return $rows->map(function ($row) use ($lineIndexes) {
            $debitIndex = $lineIndexes->get($row->debit_line_id);
            $creditIndex = $lineIndexes->get($row->credit_line_id);
            if ($debitIndex === null || $creditIndex === null) {
                throw new AccountingConflict('Journal allocation references a missing line.',
                    reason: AccountingConflictReason::CashFlowInvalidAllocation);
            }

            return ['debit_line_index' => $debitIndex, 'credit_line_index' => $creditIndex,
                'amount' => $row->amount, 'category' => $row->category];
        })->all();
    }
}
