<?php

namespace App\Accounting\Actions;

use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReverseJournalEntry
{
    public function execute(User $actor, int $journalEntryId): JournalEntry
    {
        return DB::transaction(function () use ($actor, $journalEntryId) {
            $original = JournalEntry::ownedBy($actor)->whereKey($journalEntryId)->lockForUpdate()->firstOrFail();
            if (! $original->isPosted() || $original->reversal_of_id !== null) {
                throw new AccountingConflict('Only an original posted journal can be reversed.');
            }
            if ($original->reversal()->lockForUpdate()->first() !== null) {
                throw new AccountingConflict('Journal has already been reversed.');
            }

            $lines = $original->lines()->lockForUpdate()->get();
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

            $reversal = new JournalEntry([
                'entry_date' => now()->toDateString(),
                'currency' => $original->currency,
                'reference' => 'REV-'.$original->id,
                'description' => 'Reversal of journal entry #'.$original->id,
            ]);
            $reversal->user_id = $actor->getKey();
            $reversal->reversal_of_id = $original->id;
            $reversal->save();

            foreach ($reversedLines as $fields) {
                $line = new JournalLine([
                    'chart_account_id' => $fields['chart_account_id'],
                    'debit' => $fields['debit'],
                    'credit' => $fields['credit'],
                    'description' => $fields['description'],
                ]);
                $line->user_id = $original->user_id;
                $line->line_number = $fields['line_number'];
                $reversal->lines()->save($line);
            }

            $reversal->status = 'posted';
            $reversal->posted_at = now();
            $reversal->version++;
            $reversal->save();

            return $reversal->load('lines');
        }, 3);
    }
}
