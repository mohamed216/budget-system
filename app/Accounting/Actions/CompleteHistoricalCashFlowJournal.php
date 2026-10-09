<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashAccountRole;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\HistoricalCashFlowAllocationInput;
use App\Accounting\HistoricalCashFlowCompletionOutcome;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CompleteHistoricalCashFlowJournal
{
    /** @param array<int, array<string, mixed>> $allocations */
    public function execute(User $actor, int $journalId, array $allocations): HistoricalCashFlowCompletionOutcome
    {
        $reviewed = [];
        foreach ($allocations as $index => $allocation) {
            $reviewed[] = HistoricalCashFlowAllocationInput::fromArray($allocation, $index);
        }

        return DB::transaction(function () use ($actor, $journalId, $reviewed) {
            AccountingPeriodLocks::owner($actor);
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if (! $journal->isPosted()) {
                $this->conflict(AccountingConflictReason::CashFlowJournalNotPosted, 'Only posted journals can be reviewed.');
            }
            if ($journal->openingBalanceBatch()->exists() || $journal->fiscalYearClose()->exists()) {
                $this->conflict(AccountingConflictReason::CashFlowSpecialJournal, 'Special journals are excluded from cash flow activity review.');
            }
            if ($journal->reversal_of_id !== null) {
                $this->conflict(AccountingConflictReason::CashFlowReversalRequiresInheritance, 'Reversal allocations require inherited classification.');
            }

            $lines = JournalLine::ownedBy($actor)->where('journal_entry_id', $journalId)
                ->orderBy('id')->lockForUpdate()->get();
            $accountIds = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($lines->count() < 2 || $accounts->count() !== count($accountIds)) {
                $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Posted journal lines or accounts are incomplete.');
            }

            $lineAmounts = [];
            $cashLines = [];
            $debitTotal = DecimalAmount::fromString('0');
            $creditTotal = DecimalAmount::fromString('0');
            foreach ($lines as $line) {
                $role = CashAccountRole::tryFrom((string) ($accounts->get($line->chart_account_id)?->cash_role ?? ''));
                if ($role === null) {
                    $this->conflict(AccountingConflictReason::CashFlowUnreviewedAccount, 'Every account in this journal needs a reviewed cash role.');
                }
                try {
                    $debit = DecimalAmount::fromString($line->getRawOriginal('debit'));
                    $credit = DecimalAmount::fromString($line->getRawOriginal('credit'));
                } catch (InvalidArgumentException) {
                    $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Posted journal money is invalid.');
                }
                if ($debit->isPositive() === $credit->isPositive()) {
                    $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Posted journal line must have one positive side.');
                }
                $debitTotal = $debitTotal->add($debit);
                $creditTotal = $creditTotal->add($credit);
                $lineAmounts[$line->id] = ['side' => $debit->isPositive() ? 'debit' : 'credit',
                    'amount' => $debit->isPositive() ? $debit : $credit, 'cash' => $role->isCash()];
                if ($role->isCash()) {
                    $cashLines[] = $line->id;
                }
            }
            if (! $debitTotal->isPositive() || ! $debitTotal->equals($creditTotal)) {
                $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Posted journal does not balance.');
            }

            $existing = DB::table('journal_line_allocations')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->get();
            $completion = DB::table('cash_flow_journal_completions')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->lockForUpdate()->first();
            if ($completion !== null) {
                $this->conflict(AccountingConflictReason::CashFlowAlreadyCompleted, 'Cash flow review is already sealed.');
            }
            if ($cashLines === []) {
                if ($reviewed !== [] || $existing->isNotEmpty()) {
                    $this->conflict(AccountingConflictReason::CashFlowNoCashLines, 'This journal has no cash lines to allocate.');
                }
                return HistoricalCashFlowCompletionOutcome::NoCashLines;
            }

            $covered = [];
            foreach ($reviewed as $row) {
                $debit = $lineAmounts[$row->debitLineId] ?? null;
                $credit = $lineAmounts[$row->creditLineId] ?? null;
                if ($debit === null || $credit === null || $row->debitLineId === $row->creditLineId
                    || $debit['side'] !== 'debit' || $credit['side'] !== 'credit'
                    || (! $debit['cash'] && ! $credit['cash'])
                    || (($debit['cash'] xor $credit['cash']) !== ($row->category !== null))) {
                    $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Allocation lines or category are invalid.');
                }
                foreach ([$row->debitLineId, $row->creditLineId] as $lineId) {
                    $covered[$lineId] = ($covered[$lineId] ?? DecimalAmount::fromString('0'))->add($row->amount);
                    if ($covered[$lineId]->compare($lineAmounts[$lineId]['amount']) > 0) {
                        $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Allocation exceeds a journal line.');
                    }
                }
            }
            foreach ($cashLines as $lineId) {
                if (! isset($covered[$lineId]) || ! $covered[$lineId]->equals($lineAmounts[$lineId]['amount'])) {
                    $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Every cash line must be fully allocated.');
                }
            }

            if ($existing->isNotEmpty()) {
                $submitted = array_map(fn ($row) => implode('|', [
                    $row->debitLineId, $row->creditLineId, $row->amount->toDecimal(), $row->category?->value ?? '',
                ]), $reviewed);
                $stored = $existing->map(fn ($row) => implode('|', [
                    $row->debit_line_id, $row->credit_line_id, $row->amount, $row->category ?? '',
                ]))->all();
                sort($submitted, SORT_STRING);
                sort($stored, SORT_STRING);
                if ($submitted !== $stored) {
                    $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Submitted allocations differ from immutable posted allocations.');
                }
            }

            if ($existing->isEmpty()) {
                foreach ($reviewed as $row) {
                    DB::table('journal_line_allocations')->insert([
                        'user_id' => $actor->id, 'journal_entry_id' => $journalId,
                        'debit_line_id' => $row->debitLineId, 'credit_line_id' => $row->creditLineId,
                        'amount' => $row->amount->toDecimal(), 'category' => $row->category?->value,
                    ]);
                }
            }
            DB::table('cash_flow_journal_completions')->insert([
                'user_id' => $actor->id, 'journal_entry_id' => $journalId,
                'completed_at' => now()->format('Y-m-d H:i:s.u'),
            ]);

            return HistoricalCashFlowCompletionOutcome::Completed;
        }, 3);
    }

    private function conflict(AccountingConflictReason $reason, string $message): never
    {
        throw new AccountingConflict($message, reason: $reason);
    }
}
