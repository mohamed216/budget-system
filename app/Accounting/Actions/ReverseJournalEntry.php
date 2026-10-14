<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashFlowAllocationValidator;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\OperationalAccounting\OperationalAssetReservation;
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationHistoryDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReverseJournalEntry
{
    public function execute(User $actor, int $journalEntryId): JournalEntry
    {
        if (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->reverse($actor, $journalEntryId);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict
                    || DB::transactionLevel() !== 0 || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable reversal rediscovery state.');
    }

    private function reverse(User $actor, int $journalEntryId): JournalEntry
    {
        return DB::transaction(function () use ($actor, $journalEntryId) {
            AccountingPeriodLocks::owner($actor);
            // Resolve ownership before checking the current period, without taking a journal lock.
            $candidate = JournalEntry::ownedBy($actor)->whereKey($journalEntryId)->first();
            $accountIds = $candidate?->lines()->pluck('chart_account_id')->unique()->sort()->values()->all() ?? [];
            $reversalDate = now()->toDateString();
            $periodConflict = null;
            try {
                (new PeriodGuard)->assertOpen($actor, $reversalDate);
            } catch (AccountingConflict $exception) {
                if ($candidate !== null) {
                    throw $exception;
                }
                // Candidate absence is not authoritative ownership. Preserve
                // not-found semantics before surfacing this owner's date guard.
                $periodConflict = $exception;
            }
            // Lock the fiscal-year link before the journal header, preserving owner -> date guards -> fiscal close -> journal.
            if (FiscalYearClose::ownedBy($actor)->where('journal_entry_id', $journalEntryId)->lockForUpdate()->first(['id']) !== null) {
                throw new AccountingConflict('Fiscal-year closing journals cannot be reversed through the generic reversal action.');
            }
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            $participation = (new OperationalReconciliationChartDependencies)->lockParticipationWithAccountTargets($actor->id);
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
            // Current-read the original and the reversal uniqueness range together,
            // in ID order. Candidate negative existence is never authoritative.
            $headers = JournalEntry::ownedBy($actor)->where(fn ($q) => $q->where('id', $journalEntryId)
                ->orWhere('reversal_of_id', $journalEntryId))->orderBy('id')->lockForUpdate()->get();
            $original = $headers->find($journalEntryId) ?? throw (new ModelNotFoundException)->setModel(JournalEntry::class, [$journalEntryId]);
            if ($periodConflict !== null) {
                throw $periodConflict;
            }
            if (! $original->isPosted() || $original->reversal_of_id !== null) {
                throw new AccountingConflict('Only an original posted journal can be reversed.');
            }
            if ($headers->contains(fn ($header) => $header->reversal_of_id !== null && (string) $header->reversal_of_id === (string) $journalEntryId)) {
                throw new AccountingConflict('Journal has already been reversed.');
            }
            $lines = $original->lines()->lockForUpdate()->get();
            $currentIds = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
            if (array_map('strval', $accountIds) !== array_map('strval', $currentIds)) {
                throw new AccountingConflict('Reversal chart sources changed; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($lines->count() < 2) {
                throw new AccountingConflict('Reversal requires at least two original journal lines.');
            }

            $totalDebit = DecimalAmount::fromString('0');
            $totalCredit = DecimalAmount::fromString('0');
            $reversedLines = [];
            foreach ($lines as $line) {
                if ((string) $line->user_id !== (string) $original->user_id) {
                    throw new AccountingConflict('Journal line ownership does not match the journal.');
                }
                try {
                    $debit = DecimalAmount::fromString($line->getRawOriginal('debit'));
                    $credit = DecimalAmount::fromString($line->getRawOriginal('credit'));
                } catch (InvalidArgumentException $exception) {
                    throw new AccountingConflict('Persisted journal line contains invalid accounting money.', 0, $exception);
                }
                if ($debit->isPositive() === $credit->isPositive()) {
                    throw new AccountingConflict('Each journal line must have exactly one positive side.');
                }
                $totalDebit = $totalDebit->add($debit);
                $totalCredit = $totalCredit->add($credit);
                $reversedLines[] = [
                    'chart_account_id' => $line->chart_account_id,
                    'line_number' => $line->line_number,
                    'debit' => $credit->toDecimal(),
                    'credit' => $debit->toDecimal(),
                    'description' => $line->description,
                ];
            }
            if (! $totalDebit->isPositive() || ! $totalDebit->equals($totalCredit)) {
                throw new AccountingConflict('Reversal requires equal debit and credit totals with a positive total.');
            }

            $originalAllocations = [];
            $hasCash = false;
            $allocationRows = DB::table('journal_line_allocations')->where('user_id', $actor->id)
                ->where('journal_entry_id', $original->id)->orderBy('id')->lockForUpdate()->get();
            $completion = DB::table('cash_flow_journal_completions')->where('user_id', $actor->id)
                ->where('journal_entry_id', $original->id)->lockForUpdate()->first();
            // Opening provenance is a current read after headers/lines, never a
            // consistent-read exists() that can hide a committed special link.
            $isOpeningBalance = DB::table('opening_balance_batches')->where('user_id', $actor->id)
                ->where('journal_entry_id', $original->id)->orderBy('id')->lockForUpdate()->first() !== null;
            $operationalLink = DB::table('operational_transaction_journals')->where('user_id', $actor->id)
                ->where('journal_entry_id', $original->id)->sharedLock()->first(['transaction_id']);
            if ($operationalLink !== null) {
                throw new AccountingConflict('Integrated operational journals cannot be reversed through the generic reversal action.',
                    reason: AccountingConflictReason::OperationalJournalLinked);
            }
            if ((new OperationalAssetReservation)->touchesReservedAsset($actor->id, $integration?->state,
                $participation['account_chart_targets'], $accounts)) {
                throw new AccountingConflict('Generic journal reversal cannot touch an ACTIVE reserved operational asset.',
                    reason: AccountingConflictReason::OperationalAssetReserved);
            }
            if (! $isOpeningBalance) {
                $validator = new CashFlowAllocationValidator;
                $originalAllocations = $validator->fromPersisted($allocationRows);
                $hasCash = $validator->validate($lines, $accounts, $originalAllocations, complete: true);
                if ($hasCash !== ($completion !== null)) {
                    throw new AccountingConflict('Original journal cash-flow completion is inconsistent.',
                        reason: AccountingConflictReason::CashFlowInvalidAllocation);
                }
            }

            $reversal = new JournalEntry([
                'entry_date' => $reversalDate,
                'currency' => $original->currency,
                'reference' => 'REV-'.$original->id,
                'description' => 'Reversal of journal entry #'.$original->id,
            ]);
            $reversal->user_id = $actor->getKey();
            $reversal->reversal_of_id = $original->id;
            $reversal->save();

            $reversedLineIdsByOriginalId = [];
            $reversalSources = [];
            foreach ($reversedLines as $index => $fields) {
                $line = new JournalLine([
                    'chart_account_id' => $fields['chart_account_id'],
                    'debit' => $fields['debit'],
                    'credit' => $fields['credit'],
                    'description' => $fields['description'],
                ]);
                $line->user_id = $original->user_id;
                $line->line_number = $fields['line_number'];
                $reversal->lines()->save($line);
                $reversalSources[] = $line->getAttributes();
                $reversedLineIdsByOriginalId[$lines[$index]->id] = $line->id;
            }

            if ($hasCash) {
                (new CopyReversalCashFlowAllocations)->copy($actor, $reversal->id, $originalAllocations,
                    $reversedLineIdsByOriginalId);
            }

            // Newly created draft sources are owned by this transaction. Protect
            // their completion range before evidence/parent locks as well.
            DB::table('cash_flow_journal_completions')->where('user_id', $actor->id)
                ->where('journal_entry_id', $reversal->id)->lockForUpdate()->first();
            $review = (new OperationalReconciliationHistoryDependencies)->lockBoundReview($actor->id, $integration, [
                'journal_ids' => [$original->id, $reversal->id], 'chart_ids' => $accounts->modelKeys(),
                'lines' => array_merge($lines->map(fn ($line) => $line->getAttributes())->all(), $reversalSources),
            ]);
            $relevant = $review !== null && (in_array((string) $original->id, array_map('strval', $review['journal_ids']), true)
                || array_intersect(array_map('strval', $accountIds), array_map('strval', $review['chart_ids'])) !== []);
            $changes = $relevant ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            $reversal->status = 'posted';
            $reversal->posted_at = now();
            $reversal->version++;
            $reversal->save();
            if ($hasCash) {
                DB::table('cash_flow_journal_completions')->insert([
                    'user_id' => $actor->id, 'journal_entry_id' => $reversal->id,
                    'completed_at' => now()->format('Y-m-d H:i:s.u'),
                ]);
            }

            return $reversal->load('lines');
        }, 3);
    }
}
