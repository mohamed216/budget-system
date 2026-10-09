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
use App\Models\JournalLine;
use App\Models\FiscalYearClose;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReverseJournalEntry
{
    public function execute(User $actor, int $journalEntryId): JournalEntry
    {
        return DB::transaction(function () use ($actor, $journalEntryId) {
            AccountingPeriodLocks::owner($actor);
            // Resolve ownership before checking the current period, without taking a journal lock.
            JournalEntry::ownedBy($actor)->whereKey($journalEntryId)->firstOrFail();
            $reversalDate = now()->toDateString();
            (new PeriodGuard)->assertOpen($actor, $reversalDate);
            // Lock the fiscal-year link before the journal header, preserving owner -> date guards -> fiscal close -> journal.
            if (FiscalYearClose::ownedBy($actor)->where('journal_entry_id', $journalEntryId)->lockForUpdate()->first(['id']) !== null) {
                throw new AccountingConflict('Fiscal-year closing journals cannot be reversed through the generic reversal action.');
            }
            $original = JournalEntry::ownedBy($actor)->whereKey($journalEntryId)->lockForUpdate()->firstOrFail();
            if (! $original->isPosted() || $original->reversal_of_id !== null) {
                throw new AccountingConflict('Only an original posted journal can be reversed.');
            }
            if ($original->reversal()->lockForUpdate()->first() !== null) {
                throw new AccountingConflict('Journal has already been reversed.');
            }
            $isOpeningBalance = $original->openingBalanceBatch()->exists();

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

            $originalAllocations = [];
            $hasCash = false;
            if (! $isOpeningBalance) {
                $accountIds = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
                $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)
                    ->orderBy('id')->lockForUpdate()->get();
                $allocationRows = DB::table('journal_line_allocations')->where('user_id', $actor->id)
                    ->where('journal_entry_id', $original->id)->orderBy('id')->lockForUpdate()->get();
                $completion = DB::table('cash_flow_journal_completions')->where('user_id', $actor->id)
                    ->where('journal_entry_id', $original->id)->lockForUpdate()->first();
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
                $reversedLineIdsByOriginalId[$lines[$index]->id] = $line->id;
            }

            if ($hasCash) {
                (new CopyReversalCashFlowAllocations)->copy($actor, $reversal->id, $originalAllocations,
                    $reversedLineIdsByOriginalId);
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
