<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashAccountRole;
use App\Accounting\CashFlowAllocationValidator;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\HistoricalCashFlowAllocationInput;
use App\Accounting\HistoricalCashFlowCompletionOutcome;
use App\Models\ChartAccount;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationHistoryDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        if (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->complete($actor, $journalId, $reviewed);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict
                    || DB::transactionLevel() !== 0 || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable historical completion rediscovery state.');
    }

    /** @param list<HistoricalCashFlowAllocationInput> $reviewed */
    private function complete(User $actor, int $journalId, array $reviewed): HistoricalCashFlowCompletionOutcome
    {
        return DB::transaction(function () use ($actor, $journalId, $reviewed) {
            AccountingPeriodLocks::owner($actor);
            $candidate = JournalEntry::ownedBy($actor)->whereKey($journalId)->first();
            $accountIds = $candidate?->lines()->pluck('chart_account_id')->unique()->sort()->values()->all() ?? [];
            // Historical classification does not post a new dated movement:
            // closed periods are permitted. This is special provenance only.
            $fiscal = FiscalYearClose::ownedBy($actor)->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->first(['id']);
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            (new OperationalReconciliationChartDependencies)->lockParticipation($actor->id);
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if (! $journal->isPosted()) {
                $this->conflict(AccountingConflictReason::CashFlowJournalNotPosted, 'Only posted journals can be reviewed.');
            }
            $lines = JournalLine::ownedBy($actor)->where('journal_entry_id', $journalId)
                ->orderBy('id')->lockForUpdate()->get();
            if ($candidate === null || $candidate->entry_date->toDateString() !== $journal->entry_date->toDateString()
                || array_map('strval', $lines->pluck('chart_account_id')->unique()->sort()->values()->all()) !== array_map('strval', $accountIds)) {
                $this->conflict(AccountingConflictReason::ReconciliationDiscoveryConflict, 'Historical journal sources changed; rediscovery required.');
            }
            $existing = DB::table('journal_line_allocations')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->get();
            $completion = DB::table('cash_flow_journal_completions')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->lockForUpdate()->first();
            $opening = DB::table('opening_balance_batches')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->first(['id']);
            if ($opening !== null || $fiscal !== null) {
                $this->conflict(AccountingConflictReason::CashFlowSpecialJournal, 'Special journals are excluded from cash flow activity review.');
            }
            if ($journal->reversal_of_id !== null) {
                $this->conflict(AccountingConflictReason::CashFlowReversalRequiresInheritance, 'Reversal allocations require inherited classification.');
            }
            if ($lines->count() < 2 || $accounts->count() !== count($accountIds)) {
                $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Posted journal lines or accounts are incomplete.');
            }

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
                if ($role->isCash()) {
                    $cashLines[] = $line->id;
                }
            }
            if (! $debitTotal->isPositive() || ! $debitTotal->equals($creditTotal)) {
                $this->conflict(AccountingConflictReason::CashFlowInvalidAllocation, 'Posted journal does not balance.');
            }

            if ($completion !== null) {
                $this->conflict(AccountingConflictReason::CashFlowAlreadyCompleted, 'Cash flow review is already sealed.');
            }
            if ($cashLines === []) {
                if ($reviewed !== [] || $existing->isNotEmpty()) {
                    $this->conflict(AccountingConflictReason::CashFlowNoCashLines, 'This journal has no cash lines to allocate.');
                }

                return HistoricalCashFlowCompletionOutcome::NoCashLines;
            }

            (new CashFlowAllocationValidator)->validate($lines, $accounts, $reviewed, complete: true);

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

            $review = (new OperationalReconciliationHistoryDependencies)->lockBoundReview($actor->id, $integration, [
                'journal_ids' => [$journalId], 'chart_ids' => $accounts->modelKeys(),
                'lines' => $lines->map(fn ($line) => $line->getAttributes())->all(),
            ]);
            $relevant = $review !== null && in_array((string) $journalId, array_map('strval', $review['journal_ids']), true);
            $changes = $relevant ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            if ($existing->isEmpty()) {
                foreach ($reviewed as $row) {
                    DB::table('journal_line_allocations')->insert([
                        'user_id' => $actor->id, 'journal_entry_id' => $journalId,
                        'debit_line_id' => $row->debitLineId, 'credit_line_id' => $row->creditLineId,
                        'amount' => $row->amount->toDecimal(), 'category' => $row->category?->value,
                    ]);
                }
            }
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
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
