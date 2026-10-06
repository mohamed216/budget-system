<?php

namespace App\Accounting\Actions;

use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
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
            $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
            if ($journal->isPosted()) {
                return $journal;
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
            $journal->status = 'posted';
            $journal->posted_at = now();
            $journal->version++;
            $journal->save();

            return $journal->setRelation('lines', $lines);
        }, 3);
    }
}
