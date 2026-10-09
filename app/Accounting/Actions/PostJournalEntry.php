<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashFlowAllocationValidator;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostJournalEntry
{
    public function execute(User $actor, int $journalId): JournalEntry
    {
        return DB::transaction(function () use ($actor, $journalId) {
            AccountingPeriodLocks::owner($actor);
            // Read ownership and date without a journal lock; the owner lock serializes all app writes.
            $candidate = JournalEntry::ownedBy($actor)->whereKey($journalId)->firstOrFail();
            $periodConflict = null;
            if (! $candidate->isPosted()) {
                try {
                    (new PeriodGuard)->assertOpen($actor, $candidate->entry_date->toDateString());
                } catch (AccountingConflict $exception) {
                    // A prior repeatable-read snapshot can show a draft already posted by another transaction.
                    $periodConflict = $exception;
                }
            }
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if ($journal->isPosted()) {
                return $journal;
            }
            if ($journal->entry_date->toDateString() !== $candidate->entry_date->toDateString()) {
                throw new AccountingConflict('Journal date changed while posting; reload the draft.');
            }
            if ($periodConflict !== null) {
                throw $periodConflict;
            }
            if (! $journal->isDraft()) {
                throw new AccountingConflict('Only draft journals can be posted.');
            }
            $lines = $journal->lines()->lockForUpdate()->get();
            $ids = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
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
            $journal->status = 'posted';
            $journal->posted_at = now();
            $journal->version++;
            $journal->save();
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
