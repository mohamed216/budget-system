<?php

namespace App\Accounting\Queries;

use App\Accounting\CashFlowAllocationValidator;
use App\Accounting\CashFlowStatement;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\SignedDecimalAmount;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class StatementOfCashFlowsQuery
{
    public function execute(User $owner, string $startDate, string $endDate): CashFlowStatement
    {
        Validator::make(['start_date' => $startDate, 'end_date' => $endDate], [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ])->validate();

        $connection = DB::connection();
        if ($connection->transactionLevel() === 0) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($owner, $startDate, $endDate): CashFlowStatement {
            $readiness = new CashFlowReadiness;
            $currency = $readiness->cumulativeCurrency($owner, $endDate);
            $period = $readiness->period($owner, $startDate, $endDate);
            $hasCashAccounts = ChartAccount::ownedBy($owner)
                ->whereIn('cash_role', ['cash', 'cash_equivalent'])->exists();

            $balances = $this->cashBalances($owner, $startDate, $endDate);
            $beginning = SignedDecimalAmount::difference($balances->beginning_debits, $balances->beginning_credits);
            $ending = SignedDecimalAmount::difference($balances->ending_debits, $balances->ending_credits);
            $opening = $this->openingAdjustments($owner, $period['opening']);
            $activities = $this->activities($owner, $period['ordinary_cash']);
            $netCashFlow = $activities['operating']->add($activities['investing'])->add($activities['financing']);
            $netChange = $opening->add($netCashFlow);
            if (! $beginning->add($netChange)->equals($ending)) {
                $this->corrupt();
            }

            return new CashFlowStatement($startDate, $endDate, $currency, $hasCashAccounts,
                $beginning, $opening, $activities['operating'], $activities['investing'],
                $activities['financing'], $netCashFlow, $netChange, $ending);
        });
    }

    private function cashBalances(User $owner, string $startDate, string $endDate): object
    {
        return $this->cashLines($owner)
            ->where('je.entry_date', '<=', $endDate)
            ->selectRaw("COALESCE(SUM(CASE WHEN je.entry_date < ? THEN jl.debit ELSE 0.00 END), 0.00) AS beginning_debits,
                COALESCE(SUM(CASE WHEN je.entry_date < ? THEN jl.credit ELSE 0.00 END), 0.00) AS beginning_credits,
                COALESCE(SUM(jl.debit), 0.00) AS ending_debits,
                COALESCE(SUM(jl.credit), 0.00) AS ending_credits", [$startDate, $startDate])
            ->first();
    }

    /** @param list<int> $journalIds */
    private function openingAdjustments(User $owner, array $journalIds): SignedDecimalAmount
    {
        if ($journalIds === []) {
            return SignedDecimalAmount::zero();
        }
        $totals = $this->cashLines($owner)->whereIn('je.id', $journalIds)
            ->selectRaw('COALESCE(SUM(jl.debit), 0.00) AS debits,
                COALESCE(SUM(jl.credit), 0.00) AS credits')->first();

        return SignedDecimalAmount::difference($totals->debits, $totals->credits);
    }

    private function cashLines(User $owner): \Illuminate\Database\Query\Builder
    {
        return DB::table('journal_entries as je')
            ->join('journal_lines as jl', function ($join): void {
                $join->on('jl.journal_entry_id', '=', 'je.id')->on('jl.user_id', '=', 'je.user_id');
            })
            ->join('chart_of_accounts as ca', function ($join): void {
                $join->on('ca.id', '=', 'jl.chart_account_id')->on('ca.user_id', '=', 'jl.user_id');
            })
            ->where('je.user_id', $owner->id)->where('je.status', 'posted')
            ->whereIn('ca.cash_role', ['cash', 'cash_equivalent']);
    }

    /** @param list<int> $journalIds
     *  @return array{operating:SignedDecimalAmount, investing:SignedDecimalAmount, financing:SignedDecimalAmount}
     */
    private function activities(User $owner, array $journalIds): array
    {
        $totals = ['operating' => SignedDecimalAmount::zero(),
            'investing' => SignedDecimalAmount::zero(), 'financing' => SignedDecimalAmount::zero()];
        if ($journalIds === []) {
            return $totals;
        }
        $lines = JournalLine::ownedBy($owner)->whereIn('journal_entry_id', $journalIds)
            ->orderBy('journal_entry_id')->orderBy('id')->get()->groupBy('journal_entry_id');
        $accountIds = $lines->flatten(1)->pluck('chart_account_id')->unique()->all();
        $accounts = ChartAccount::ownedBy($owner)->whereIn('id', $accountIds)->get();
        $byAccount = $accounts->keyBy('id');
        $rows = DB::table('journal_line_allocations')->where('user_id', $owner->id)
            ->whereIn('journal_entry_id', $journalIds)->orderBy('journal_entry_id')->orderBy('id')
            ->get()->groupBy('journal_entry_id');
        $validator = new CashFlowAllocationValidator;

        foreach ($journalIds as $journalId) {
            $journalLines = $lines->get($journalId, collect());
            $journalAccounts = $journalLines->pluck('chart_account_id')->unique()
                ->map(fn ($id) => $byAccount->get($id))->filter()->values();
            try {
                $allocations = $validator->fromPersisted($rows->get($journalId, collect()));
                if (! $validator->validate($journalLines, $journalAccounts, $allocations, complete: true)) {
                    $this->corrupt();
                }
            } catch (AccountingConflict) {
                $this->corrupt();
            }
            $byLine = $journalLines->keyBy('id');
            foreach ($allocations as $allocation) {
                $debit = $byLine->get($allocation->debitLineId);
                $credit = $byLine->get($allocation->creditLineId);
                $debitCash = in_array($byAccount->get($debit->chart_account_id)?->cash_role, ['cash', 'cash_equivalent'], true);
                $creditCash = in_array($byAccount->get($credit->chart_account_id)?->cash_role, ['cash', 'cash_equivalent'], true);
                if ($debitCash && $creditCash) {
                    continue;
                }
                $category = $allocation->category?->value;
                if ($category === null || ! isset($totals[$category])) {
                    $this->corrupt();
                }
                $signed = SignedDecimalAmount::fromAggregateString($allocation->amount->toDecimal());
                $totals[$category] = $totals[$category]->add($debitCash ? $signed : $signed->negate());
            }
        }

        return $totals;
    }

    private function corrupt(): never
    {
        throw new AccountingConflict('Posted cash-flow data is inconsistent.',
            reason: AccountingConflictReason::CashFlowCorruptData);
    }
}
