<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\FiscalYearCloseInput;
use App\Accounting\FiscalYearCloseOverlap;
use App\Accounting\FiscalYearProfitLoss;
use App\Models\AccountingPeriod;
use App\Models\ChartAccount;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CloseFiscalYear
{
    public function execute(User $actor, string $startDate, string $endDate, string $currency, mixed $retainedEarningsAccountId): FiscalYearClose
    {
        $input = FiscalYearCloseInput::validate($startDate, $endDate, $currency, $retainedEarningsAccountId);

        return DB::transaction(function () use ($actor, $input) {
            AccountingPeriodLocks::owner($actor);
            $start = $input['start_date'];
            $end = $input['end_date'];

            AccountingPeriod::ownedBy($actor)->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)->orderBy('id')->lockForUpdate()->get(['id']);
            if ((new FiscalYearCloseOverlap)->exists($actor, $start, $end, lockForUpdate: true)) {
                throw new AccountingConflict('Fiscal year overlaps an existing close.');
            }
            if (JournalEntry::ownedBy($actor)->where('status', 'draft')->whereBetween('entry_date', [$start, $end])
                ->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) {
                throw new AccountingConflict('Draft journals must be resolved before closing the fiscal year.');
            }
            if (OpeningBalanceBatch::ownedBy($actor)->where('status', 'draft')->whereBetween('opening_date', [$start, $end])
                ->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) {
                throw new AccountingConflict('Draft opening balances must be resolved before closing the fiscal year.');
            }

            $posted = JournalEntry::ownedBy($actor)->where('status', 'posted')->whereBetween('entry_date', [$start, $end])
                ->orderBy('id')->lockForUpdate()->get(['id', 'user_id', 'currency']);
            foreach ($posted as $journal) {
                if ($journal->currency !== $input['currency']) {
                    throw new AccountingConflict('Posted journal currency does not match the fiscal-year currency.');
                }
            }
            $lines = JournalLine::ownedBy($actor)->whereIn('journal_entry_id', $posted->pluck('id')->all())
                ->orderBy('journal_entry_id')->orderBy('id')->lockForUpdate()->get();

            $zero = DecimalAmount::fromString('0.00');
            $journalTotals = [];
            foreach ($posted as $journal) {
                $journalTotals[$journal->id] = ['debit' => $zero, 'credit' => $zero, 'count' => 0];
            }
            try {
                foreach ($lines as $line) {
                    $totals = &$journalTotals[$line->journal_entry_id];
                    $debit = DecimalAmount::fromString($line->getRawOriginal('debit'));
                    $credit = DecimalAmount::fromString($line->getRawOriginal('credit'));
                    if ($debit->isPositive() === $credit->isPositive()) {
                        throw new AccountingConflict('Posted journal contains an invalid line.');
                    }
                    $totals['debit'] = $totals['debit']->add($debit);
                    $totals['credit'] = $totals['credit']->add($credit);
                    $totals['count']++;
                    unset($totals);
                }
            } catch (InvalidArgumentException $exception) {
                throw new AccountingConflict('Posted journal contains invalid decimal amounts.', 0, $exception);
            }
            foreach ($journalTotals as $totals) {
                if ($totals['count'] < 2 || ! $totals['debit']->isPositive() || ! $totals['debit']->equals($totals['credit'])) {
                    throw new AccountingConflict('Posted journal is not individually balanced.');
                }
            }

            $accountIds = $lines->pluck('chart_account_id')->unique()->sort()->values()->all();
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)
                ->whereIn('type', ['revenue', 'expense'])->orderBy('id')->lockForUpdate()->get();
            $retained = ChartAccount::ownedBy($actor)->whereKey((int) $input['retained_earnings_account_id'])
                ->lockForUpdate()->first();
            if ($retained === null || ! $retained->is_active || $retained->type !== 'equity') {
                throw new AccountingConflict('Retained earnings account must be an owned, active equity account.');
            }

            try {
                $closingLines = (new FiscalYearProfitLoss)->closingLines($lines, $accounts, $retained->id);
            } catch (InvalidArgumentException $exception) {
                throw new AccountingConflict('Fiscal-year closing amount exceeds DECIMAL(15,2).', 0, $exception);
            }
            $closedAt = now();
            $journal = null;
            if ($closingLines !== []) {
                $journal = new JournalEntry([
                    'entry_date' => $end, 'currency' => $input['currency'],
                    'reference' => 'FYC-'.$start.'-'.$end,
                    'description' => 'Fiscal-year close '.$start.' through '.$end,
                ]);
                $journal->user_id = $actor->getKey();
                $journal->save();
                foreach ($closingLines as $index => $fields) {
                    $line = new JournalLine($fields);
                    $line->user_id = $actor->getKey();
                    $line->line_number = $index + 1;
                    $journal->lines()->save($line);
                }
                $journal->status = 'posted';
                $journal->posted_at = $closedAt;
                $journal->version++;
                $journal->save();
            }

            $close = new FiscalYearClose([
                'start_date' => $start, 'end_date' => $end, 'currency' => $input['currency'],
            ]);
            $close->user_id = $actor->getKey();
            $close->retained_earnings_account_id = $retained->id;
            $close->journal_entry_id = $journal?->id;
            $close->closed_at = $closedAt;
            $close->save();

            return $close->load('journalEntry.lines');
        }, 3);
    }
}
