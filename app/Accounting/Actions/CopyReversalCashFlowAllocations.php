<?php

namespace App\Accounting\Actions;

use App\Accounting\HistoricalCashFlowAllocationInput;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CopyReversalCashFlowAllocations
{
    /**
     * Called inside the reversal Action transaction, after original allocations are locked.
     * @param list<HistoricalCashFlowAllocationInput> $original
     * @param array<int, int> $reversedLineIdsByOriginalId
     */
    public function copy(User $actor, int $reversalId, array $original, array $reversedLineIdsByOriginalId): void
    {
        foreach ($original as $row) {
            DB::table('journal_line_allocations')->insert([
                'user_id' => $actor->id, 'journal_entry_id' => $reversalId,
                'debit_line_id' => $reversedLineIdsByOriginalId[$row->creditLineId],
                'credit_line_id' => $reversedLineIdsByOriginalId[$row->debitLineId],
                'amount' => $row->amount->toDecimal(), 'category' => $row->category?->value,
            ]);
        }
    }
}
