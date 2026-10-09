<?php

namespace App\Accounting\Queries;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Cumulative posted-ledger integrity and period-specific classification readiness. */
final class CashFlowReadiness
{
    public function cumulativeCurrency(User $owner, string $endDate): string
    {
        $integrity = new PostedLedgerIntegrity;
        $posted = $integrity->postedScope($owner)->where('integrity_entries.entry_date', '<=', $endDate);
        $summary = $integrity->summary($posted)->first();
        $currency = $integrity->currency($summary);
        $integrity->assertBalanced($summary);

        $roles = DB::table('journal_entries as je')
            ->leftJoin('journal_lines as jl', function ($join): void {
                $join->on('jl.journal_entry_id', '=', 'je.id')->on('jl.user_id', '=', 'je.user_id');
            })
            ->leftJoin('chart_of_accounts as ca', function ($join): void {
                $join->on('ca.id', '=', 'jl.chart_account_id')->on('ca.user_id', '=', 'jl.user_id');
            })
            ->leftJoin('fiscal_year_closes as fyc', function ($join): void {
                $join->on('fyc.journal_entry_id', '=', 'je.id')->on('fyc.user_id', '=', 'je.user_id');
            })
            ->where('je.user_id', $owner->id)->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $endDate)
            ->selectRaw("COALESCE(SUM(CASE WHEN jl.id IS NULL OR ca.id IS NULL
                OR NOT ((jl.debit > 0 AND jl.credit = 0) OR (jl.credit > 0 AND jl.debit = 0))
                THEN 1 ELSE 0 END), 0) AS corrupt_count,
                COALESCE(SUM(CASE WHEN jl.id IS NOT NULL AND ca.id IS NOT NULL
                AND ca.cash_role IS NULL THEN 1 ELSE 0 END), 0) AS unreviewed_count,
                COALESCE(SUM(CASE WHEN fyc.id IS NOT NULL AND CAST(ca.cash_role AS BINARY)
                    IN ('cash', 'cash_equivalent') THEN 1 ELSE 0 END), 0) AS fiscal_cash_count")
            ->first();
        if ((int) $roles->corrupt_count > 0 || (int) $roles->fiscal_cash_count > 0) {
            $this->corrupt();
        }
        if ((int) $roles->unreviewed_count > 0) {
            throw new AccountingConflict('Cash-flow classification is incomplete for posted history.',
                reason: AccountingConflictReason::CashFlowUnreviewedAccount);
        }

        return $currency;
    }

    /** @return array{opening:list<int>, ordinary_cash:list<int>} */
    public function period(User $owner, string $startDate, string $endDate): array
    {
        $rows = DB::table('journal_entries as je')
            ->leftJoin('opening_balance_batches as ob', function ($join): void {
                $join->on('ob.journal_entry_id', '=', 'je.id')->on('ob.user_id', '=', 'je.user_id');
            })
            ->leftJoin('fiscal_year_closes as fyc', function ($join): void {
                $join->on('fyc.journal_entry_id', '=', 'je.id')->on('fyc.user_id', '=', 'je.user_id');
            })
            ->leftJoin('journal_entries as original', function ($join): void {
                $join->on('original.id', '=', 'je.reversal_of_id')->on('original.user_id', '=', 'je.user_id');
            })
            ->leftJoin('opening_balance_batches as original_ob', function ($join): void {
                $join->on('original_ob.journal_entry_id', '=', 'original.id')
                    ->on('original_ob.user_id', '=', 'je.user_id');
            })
            ->leftJoin('fiscal_year_closes as original_fyc', function ($join): void {
                $join->on('original_fyc.journal_entry_id', '=', 'original.id')
                    ->on('original_fyc.user_id', '=', 'je.user_id');
            })
            ->leftJoin('cash_flow_journal_completions as completion', function ($join): void {
                $join->on('completion.journal_entry_id', '=', 'je.id')
                    ->on('completion.user_id', '=', 'je.user_id');
            })
            ->leftJoin('journal_lines as jl', function ($join): void {
                $join->on('jl.journal_entry_id', '=', 'je.id')->on('jl.user_id', '=', 'je.user_id');
            })
            ->leftJoin('chart_of_accounts as ca', function ($join): void {
                $join->on('ca.id', '=', 'jl.chart_account_id')->on('ca.user_id', '=', 'jl.user_id');
            })
            ->where('je.user_id', $owner->id)->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->selectRaw("je.id, je.reversal_of_id, original.status AS original_status,
                ob.id AS opening_id, original_ob.id AS reversed_opening_id,
                fyc.id AS fiscal_id, original_fyc.id AS reversed_fiscal_id,
                completion.id AS completion_id,
                EXISTS (SELECT 1 FROM journal_line_allocations allocation
                    WHERE allocation.journal_entry_id = je.id AND allocation.user_id = je.user_id)
                    AS has_allocations,
                COALESCE(SUM(CASE WHEN CAST(ca.cash_role AS BINARY)
                    IN ('cash', 'cash_equivalent') THEN 1 ELSE 0 END), 0) AS cash_line_count")
            ->groupBy('je.id', 'je.user_id', 'je.reversal_of_id', 'original.status',
                'ob.id', 'original_ob.id', 'fyc.id', 'original_fyc.id', 'completion.id')
            ->orderBy('je.id')->get();

        $opening = [];
        $ordinaryCash = [];
        $ordinaryReversals = [];
        foreach ($rows as $row) {
            $hasCash = (int) $row->cash_line_count > 0;
            $isOpening = $row->opening_id !== null || $row->reversed_opening_id !== null;
            $isFiscal = $row->fiscal_id !== null || $row->reversed_fiscal_id !== null;
            if (($row->reversal_of_id !== null && $row->original_status !== 'posted')
                || ($isOpening && $isFiscal) || $row->reversed_fiscal_id !== null
                || ($isFiscal && $hasCash)
                || (($isOpening || $isFiscal) && ($row->completion_id !== null || $row->has_allocations))) {
                $this->corrupt();
            }
            if ($isOpening) {
                $opening[] = (int) $row->id;
            } elseif (! $isFiscal && $hasCash) {
                if ($row->completion_id === null) {
                    if ($row->reversal_of_id !== null) {
                        $this->corrupt();
                    }
                    throw new AccountingConflict('Cash-flow classification is incomplete for a posted journal.',
                        reason: AccountingConflictReason::CashFlowClassificationIncomplete);
                }
                $ordinaryCash[] = (int) $row->id;
            } elseif (! $isFiscal && ($row->completion_id !== null || $row->has_allocations)) {
                $this->corrupt();
            }
            if (! $isOpening && ! $isFiscal && $row->reversal_of_id !== null) {
                $ordinaryReversals[(int) $row->id] = [
                    'original_id' => (int) $row->reversal_of_id,
                    'has_cash' => $hasCash,
                ];
            }
        }

        $this->validateReversalSnapshots($owner, $ordinaryReversals);

        return ['opening' => $opening, 'ordinary_cash' => $ordinaryCash];
    }

    /** @param array<int, array{original_id:int, has_cash:bool}> $reversals */
    private function validateReversalSnapshots(User $owner, array $reversals): void
    {
        if ($reversals === []) {
            return;
        }

        $journalIds = array_values(array_unique(array_merge(array_keys($reversals),
            array_column($reversals, 'original_id'))));
        $lines = DB::table('journal_lines')->where('user_id', $owner->id)
            ->whereIn('journal_entry_id', $journalIds)
            ->orderBy('journal_entry_id')->orderBy('line_number')
            ->get(['id', 'journal_entry_id', 'line_number', 'chart_account_id', 'debit', 'credit'])
            ->groupBy('journal_entry_id');
        $allocations = DB::table('journal_line_allocations')->where('user_id', $owner->id)
            ->whereIn('journal_entry_id', $journalIds)->orderBy('journal_entry_id')->orderBy('id')
            ->get(['journal_entry_id', 'debit_line_id', 'credit_line_id', 'amount', 'category'])
            ->groupBy('journal_entry_id');
        $completions = DB::table('cash_flow_journal_completions')->where('user_id', $owner->id)
            ->whereIn('journal_entry_id', $journalIds)->pluck('journal_entry_id')->all();
        $sealed = array_fill_keys($completions, true);

        foreach ($reversals as $reversalId => $details) {
            $originalId = $details['original_id'];
            if (isset($sealed[$originalId]) !== $details['has_cash']
                || isset($sealed[$reversalId]) !== $details['has_cash']) {
                $this->corrupt();
            }

            $originalLines = $lines->get($originalId, collect());
            $reversalLines = $lines->get($reversalId, collect())->keyBy('line_number');
            if ($originalLines->count() !== $reversalLines->count()) {
                $this->corrupt();
            }
            $mapped = [];
            foreach ($originalLines as $line) {
                $opposite = $reversalLines->get($line->line_number);
                if ($opposite === null || (int) $line->chart_account_id !== (int) $opposite->chart_account_id
                    || $line->debit !== $opposite->credit || $line->credit !== $opposite->debit) {
                    $this->corrupt();
                }
                $mapped[(int) $line->id] = (int) $opposite->id;
            }

            $expected = [];
            foreach ($allocations->get($originalId, collect()) as $row) {
                if (! isset($mapped[(int) $row->debit_line_id], $mapped[(int) $row->credit_line_id])) {
                    $this->corrupt();
                }
                $expected[] = json_encode([
                    $mapped[(int) $row->credit_line_id], $mapped[(int) $row->debit_line_id],
                    $row->amount, $row->category,
                ]);
            }
            $actual = [];
            foreach ($allocations->get($reversalId, collect()) as $row) {
                $actual[] = json_encode([
                    (int) $row->debit_line_id, (int) $row->credit_line_id,
                    $row->amount, $row->category,
                ]);
            }
            sort($expected, SORT_STRING);
            sort($actual, SORT_STRING);
            if ($expected !== $actual) {
                $this->corrupt();
            }
        }
    }

    private function corrupt(): never
    {
        throw new AccountingConflict('Posted cash-flow data is inconsistent.',
            reason: AccountingConflictReason::CashFlowCorruptData);
    }
}
