<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\OpeningBalanceTotals;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationHistoryDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostOpeningBalanceBatch
{
    public function execute(User $actor, int $batchId): OpeningBalanceBatch
    {
        if (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->post($actor, $batchId);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict
                    || DB::transactionLevel() !== 0 || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable opening rediscovery state.');
    }

    private function post(User $actor, int $batchId): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($actor, $batchId) {
            AccountingPeriodLocks::owner($actor);
            // The owner lock serializes application writes; this read obtains the date before period locks.
            $candidate = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->first();
            $accountIds = $candidate?->lines()->pluck('chart_account_id')->unique()->sort()->values()->all() ?? [];
            $linkedId = $candidate?->journal_entry_id;
            $periodConflict = null;
            if ($candidate !== null && ! $candidate->isPosted()) {
                try {
                    (new PeriodGuard)->assertOpen($actor, $candidate->opening_date->toDateString());
                } catch (AccountingConflict $exception) {
                    $periodConflict = $exception;
                }
            }
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            (new OperationalReconciliationChartDependencies)->lockParticipation($actor->id);
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
            $linkedJournal = $linkedId === null ? null
                : JournalEntry::ownedBy($actor)->whereKey($linkedId)->lockForUpdate()->first();
            $batch = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            $lines = $batch->lines()->lockForUpdate()->get();
            if ($batch->isPosted() && $batch->journal_entry_id === null) {
                $this->assertLinkedJournal($batch, null);
            }
            if ($candidate === null || (string) $batch->journal_entry_id !== (string) $linkedId
                || $batch->opening_date->toDateString() !== $candidate->opening_date->toDateString()
                || array_map('strval', $lines->pluck('chart_account_id')->unique()->sort()->values()->all()) !== array_map('strval', $accountIds)) {
                throw new AccountingConflict('Opening source set changed; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($batch->isPosted()) {
                $this->assertLinkedJournal($batch, $linkedJournal);

                return $batch->load('lines', 'journalEntry.lines');
            }
            if (! $batch->isDraft()) {
                throw new AccountingConflict('Only draft opening balance batches can be posted.');
            }
            if ($candidate->isPosted()) {
                throw new AccountingConflict('Current opening draft requires guard rediscovery.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($periodConflict !== null) {
                throw $periodConflict;
            }

            if ($batch->currency !== config('accounting.currency')) {
                throw new AccountingConflict('Opening balance currency must match the configured accounting currency.');
            }
            if ($accounts->count() !== count($accountIds) || $accounts->contains(fn ($account) => ! $account->is_active)) {
                throw new AccountingConflict('Posting requires existing, owned, active opening balance accounts.');
            }
            if ($lines->count() !== count($accountIds)) {
                throw new AccountingConflict('An opening balance account may appear only once.');
            }

            $journalLines = [];
            foreach ($lines as $line) {
                if ((string) $line->user_id !== (string) $batch->user_id) {
                    throw new AccountingConflict('Opening balance line ownership does not match the batch.');
                }
                $journalLines[] = [
                    'chart_account_id' => $line->chart_account_id,
                    'debit' => $line->getRawOriginal('debit'),
                    'credit' => $line->getRawOriginal('credit'),
                ];
            }
            try {
                OpeningBalanceTotals::assertBalanced($journalLines);
            } catch (InvalidArgumentException $exception) {
                throw new AccountingConflict('Persisted opening balance is not a valid balanced batch.', 0, $exception);
            }

            // The batch and account rows are locked before creating journal rows. This path
            // applies the same posting invariants without reentering the journal lock sequence.
            $journal = new JournalEntry([
                'entry_date' => $batch->opening_date->toDateString(),
                'currency' => $batch->currency,
                'reference' => 'OB-'.$batch->id,
                'description' => 'Opening balance batch #'.$batch->id,
            ]);
            $journal->user_id = $actor->getKey();
            $journal->save();
            $createdSources = [];
            foreach ($journalLines as $index => $fields) {
                $journalLine = new JournalLine($fields);
                $journalLine->user_id = $actor->getKey();
                $journalLine->line_number = $index + 1;
                $journal->lines()->save($journalLine);
                $createdSources[] = $journalLine->getAttributes();
            }
            // Created rows are transaction-owned; all source acquisition precedes
            // evidence/parent locks. No late linked-header discovery is permitted.
            $review = (new OperationalReconciliationHistoryDependencies)->lockBoundReview($actor->id, $integration, [
                'journal_ids' => [$journal->id], 'chart_ids' => $accounts->modelKeys(), 'lines' => $createdSources,
            ]);
            $relevant = $review !== null && array_intersect(array_map('strval', $accountIds), array_map('strval', $review['chart_ids'])) !== [];
            $changes = $relevant ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            $postedAt = now();
            $journal->status = 'posted';
            $journal->posted_at = $postedAt;
            $journal->version++;
            $journal->save();
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            $batch->status = 'posted';
            $batch->journal_entry_id = $journal->id;
            $batch->posted_at = $postedAt;
            $batch->save();
            $this->assertLinkedJournal($batch, $journal);

            return $batch->load('lines', 'journalEntry.lines');
        }, 3);
    }

    private function assertLinkedJournal(OpeningBalanceBatch $batch, ?JournalEntry $journal): void
    {
        if ($batch->journal_entry_id === null) {
            throw new AccountingConflict('Posted opening balance is missing its journal link.');
        }
        if ($journal === null || ! $journal->isPosted()
            || (string) $journal->id !== (string) $batch->journal_entry_id || (string) $journal->user_id !== (string) $batch->user_id
            || $journal->entry_date->toDateString() !== $batch->opening_date->toDateString()
            || $journal->currency !== $batch->currency) {
            throw new AccountingConflict('Opening balance journal link is inconsistent.');
        }
    }
}
