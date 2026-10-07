<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\OpeningBalanceTotals;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostOpeningBalanceBatch
{
    public function execute(User $actor, int $batchId): OpeningBalanceBatch
    {
        return DB::transaction(function () use ($actor, $batchId) {
            AccountingPeriodLocks::owner($actor);
            // The owner lock serializes application writes; this read obtains the date before period locks.
            $candidate = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->firstOrFail();
            $periodConflict = null;
            if (! $candidate->isPosted()) {
                try {
                    (new PeriodGuard)->assertOpen($actor, $candidate->opening_date->toDateString());
                } catch (AccountingConflict $exception) {
                    $periodConflict = $exception;
                }
            }

            $batch = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if ($batch->isPosted()) {
                $this->assertLinkedJournal($actor, $batch);

                return $batch->load('lines', 'journalEntry.lines');
            }
            if (! $batch->isDraft()) {
                throw new AccountingConflict('Only draft opening balance batches can be posted.');
            }
            if ($batch->opening_date->toDateString() !== $candidate->opening_date->toDateString()) {
                throw new AccountingConflict('Opening balance date changed while posting; reload the draft.');
            }
            if ($periodConflict !== null) {
                throw $periodConflict;
            }

            $lines = $batch->lines()->lockForUpdate()->get();
            $accountIds = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
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
            foreach ($journalLines as $index => $fields) {
                $journalLine = new JournalLine($fields);
                $journalLine->user_id = $actor->getKey();
                $journalLine->line_number = $index + 1;
                $journal->lines()->save($journalLine);
            }
            $postedAt = now();
            $journal->status = 'posted';
            $journal->posted_at = $postedAt;
            $journal->version++;
            $journal->save();

            $batch->status = 'posted';
            $batch->journal_entry_id = $journal->id;
            $batch->posted_at = $postedAt;
            $batch->save();
            $this->assertLinkedJournal($actor, $batch);

            return $batch->load('lines', 'journalEntry.lines');
        }, 3);
    }

    private function assertLinkedJournal(User $actor, OpeningBalanceBatch $batch): void
    {
        if ($batch->journal_entry_id === null) {
            throw new AccountingConflict('Posted opening balance is missing its journal link.');
        }
        $journal = JournalEntry::ownedBy($actor)->whereKey($batch->journal_entry_id)->lockForUpdate()->first();
        if ($journal === null || ! $journal->isPosted()
            || $journal->entry_date->toDateString() !== $batch->opening_date->toDateString()
            || $journal->currency !== $batch->currency) {
            throw new AccountingConflict('Opening balance journal link is inconsistent.');
        }
    }
}
