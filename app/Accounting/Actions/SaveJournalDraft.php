<?php

declare(strict_types=1);

namespace App\Accounting\Actions;

use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SaveJournalDraft
{
    /** Lines accept only chart_account_id, debit, credit, and description. */
    public function execute(User $actor, string $entryDate, string $currency, array $lines, ?string $reference = null, ?string $description = null, ?int $journalId = null, ?int $version = null): JournalEntry
    {
        $fields = Validator::make([
            'entry_date' => $entryDate, 'currency' => $currency, 'reference' => $reference, 'description' => $description,
        ], [
            'entry_date' => ['required', 'date_format:Y-m-d'], 'currency' => ['required', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string'],
        ])->validate();
        if ($currency !== config('accounting.currency')) {
            throw ValidationException::withMessages(['currency' => 'Currency must exactly match the configured accounting currency.']);
        }
        $validated = Validator::make(['lines' => $lines], [
            'lines' => ['present', 'array', 'max:65535'],
            'lines.*' => ['required', 'array:chart_account_id,debit,credit,description'],
            'lines.*.chart_account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['required', 'string'], 'lines.*.credit' => ['required', 'string'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
        ])->validate()['lines'];
        $normalized = [];
        foreach ($validated as $line) {
            $debit = DecimalAmount::fromString($line['debit']);
            $credit = DecimalAmount::fromString($line['credit']);
            if ($debit->isPositive() === $credit->isPositive()) {
                throw ValidationException::withMessages(['lines' => 'Each line must have exactly one positive side.']);
            }
            $normalized[] = ['chart_account_id' => (int) $line['chart_account_id'], 'debit' => $debit->toDecimal(),
                'credit' => $credit->toDecimal(), 'description' => $line['description'] ?? null];
        }

        return DB::transaction(function () use ($actor, $journalId, $version, $fields, $normalized) {
            if ($journalId === null) {
                if ($version !== null) {
                    throw new AccountingConflict('New drafts do not accept a version override.');
                }
                $journal = $actor->journalEntries()->create($fields);
            } else {
                $journal = JournalEntry::ownedBy($actor)->whereKey($journalId)->lockForUpdate()->firstOrFail();
                if (! $journal->isDraft()) {
                    throw new AccountingConflict('Posted journal cannot be modified.');
                }
                if ($version !== $journal->version) {
                    throw new AccountingConflict('Stale journal version: reload the draft before saving.');
                }
            }
            $journal->lines()->lockForUpdate()->get();
            $ids = array_values(array_unique(array_column($normalized, 'chart_account_id')));
            sort($ids, SORT_NUMERIC);
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($accounts->count() !== count($ids) || $accounts->contains(fn ($account) => ! $account->is_active)) {
                throw ValidationException::withMessages(['lines' => 'All chart accounts must exist, belong to the actor, and be active.']);
            }
            if ($journalId !== null) {
                $journal->lines()->delete();
                $journal->fill($fields);
                $journal->version++;
                $journal->save();
            }
            foreach ($normalized as $index => $fields) {
                $line = new JournalLine($fields);
                $line->user_id = $actor->getKey();
                $line->line_number = $index + 1;
                $journal->lines()->save($line);
            }

            return $journal->load('lines');
        }, 3);
    }
}
