<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashFlowAllocationValidator;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\OperationalAccounting\OperationalAssetReservation;
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationHistoryDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostJournalEntry
{
    public function execute(User $actor, int $journalId): JournalEntry
    {
        if (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->post($actor, $journalId);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict
                    || DB::transactionLevel() !== 0 || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable posting rediscovery state.');
    }

    private function post(User $actor, int $journalId): JournalEntry
    {
        return DB::transaction(function () use ($actor, $journalId) {
            AccountingPeriodLocks::owner($actor);
            // Read ownership and date without a journal lock; the owner lock serializes all app writes.
            $candidate = JournalEntry::ownedBy($actor)->whereKey($journalId)->first();
            $ids = $candidate?->lines()->pluck('chart_account_id')->unique()->sort()->values()->all() ?? [];
            $periodConflict = null;
            if ($candidate !== null && ! $candidate->isPosted()) {
                try {
                    (new PeriodGuard)->assertOpen($actor, $candidate->entry_date->toDateString());
                } catch (AccountingConflict $exception) {
                    // A prior repeatable-read snapshot can show a draft already posted by another transaction.
                    $periodConflict = $exception;
                }
            }
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            $participation = (new OperationalReconciliationChartDependencies)->lockParticipationWithAccountTargets($actor->id);
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if ($journal->isPosted()) {
                return $journal;
            }
            if ($candidate === null || $candidate->isPosted()) {
                throw new AccountingConflict('Current draft requires date/source rediscovery.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($journal->entry_date->toDateString() !== $candidate->entry_date->toDateString()) {
                throw new AccountingConflict('Journal date changed while posting; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($periodConflict !== null) {
                throw $periodConflict;
            }
            if (! $journal->isDraft()) {
                throw new AccountingConflict('Only draft journals can be posted.');
            }
            $lines = $journal->lines()->lockForUpdate()->get();
            $currentIds = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
            if (array_map('strval', $currentIds) !== array_map('strval', $ids)) {
                throw new AccountingConflict('Journal chart sources changed; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ((new OperationalAssetReservation)->touchesReservedAsset($actor->id, $integration?->state,
                $participation['account_chart_targets'], $accounts)) {
                throw new AccountingConflict('Generic journal posting cannot touch an ACTIVE reserved operational asset.',
                    reason: AccountingConflictReason::OperationalAssetReserved);
            }
            if ($lines->count() < 2) {
                throw new AccountingConflict('Posting requires at least two journal lines.');
            }
            if ($journal->currency !== config('accounting.currency')) {
                throw new AccountingConflict('Journal currency must exactly match the configured accounting currency.');
            }
            if ($accounts->count() !== count($ids) || $accounts->contains(fn ($account) => ! $account->is_active)) {
                throw new AccountingConflict('Posting requires existing, owned, active chart accounts.');
            }
            $totalDebit = DecimalAmount::fromString('0');
            $totalCredit = DecimalAmount::fromString('0');
            foreach ($lines as $line) {
                if ((string) $line->user_id !== (string) $journal->user_id) {
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
            }
            if (! $totalDebit->isPositive() || ! $totalDebit->equals($totalCredit)) {
                throw new AccountingConflict('Posting requires equal debit and credit totals with a positive total.');
            }
            $allocationRows = DB::table('journal_line_allocations')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->orderBy('id')->lockForUpdate()->get();
            $validator = new CashFlowAllocationValidator;
            $hasCash = $validator->validate($lines, $accounts, $validator->fromPersisted($allocationRows), complete: true);
            DB::table('cash_flow_journal_completions')->where('user_id', $actor->id)
                ->where('journal_entry_id', $journalId)->lockForUpdate()->first();
            $review = (new OperationalReconciliationHistoryDependencies)->lockBoundReview($actor->id, $integration, [
                'journal_ids' => [$journalId], 'chart_ids' => $accounts->modelKeys(),
                'lines' => $lines->map(fn ($line) => $line->getAttributes())->all(),
            ]);
            $relevant = $review !== null && array_intersect(array_map('strval', $ids), array_map('strval', $review['chart_ids'])) !== [];
            $changes = $relevant ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            // Sources are already locked; no dependency discovery is allowed after
            // the review parent. Posting, invalidation and completion are atomic.
            $journal->status = 'posted';
            $journal->posted_at = now();
            $journal->version++;
            $journal->save();
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }
            if ($hasCash) {
                DB::table('cash_flow_journal_completions')->insert([
                    'user_id' => $actor->id, 'journal_entry_id' => $journalId,
                    'completed_at' => now()->format('Y-m-d H:i:s.u'),
                ]);
            }

            return $journal->setRelation('lines', $lines);
        }, 3);
    }
}
