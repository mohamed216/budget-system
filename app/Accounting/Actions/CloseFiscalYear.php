<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\DecimalAmount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
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
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationHistoryDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CloseFiscalYear
{
    public function execute(User $actor, string $startDate, string $endDate, string $currency, mixed $retainedEarningsAccountId): FiscalYearClose
    {
        $input = FiscalYearCloseInput::validate($startDate, $endDate, $currency, $retainedEarningsAccountId);
        if (! $actor->exists || $actor->getKey() === null || $actor->getKey() <= 0) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->close($actor, $input);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict
                    || DB::transactionLevel() !== 0 || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable fiscal-close rediscovery state.');
    }

    private function close(User $actor, array $input): FiscalYearClose
    {
        return DB::transaction(function () use ($actor, $input) {
            AccountingPeriodLocks::owner($actor);
            $start = $input['start_date'];
            $end = $input['end_date'];
            // Discovery only: even an old repeatable-read view cannot authorize
            // the close. Current header and chart manifests are verified below.
            $candidates = JournalEntry::ownedBy($actor)->whereBetween('entry_date', [$start, $end])->orderBy('id')->get(['id', 'status', 'entry_date']);
            $candidatePostedIds = $candidates->where('status', 'posted')->modelKeys();
            $sourceChartIds = JournalLine::ownedBy($actor)->whereIn('journal_entry_id', $candidatePostedIds)
                ->pluck('chart_account_id')->unique()->sort()->values()->all();
            $chartIds = array_values(array_unique([...$sourceChartIds, (int) $input['retained_earnings_account_id']]));
            sort($chartIds, SORT_NUMERIC);

            AccountingPeriod::ownedBy($actor)->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)->orderBy('id')->lockForUpdate()->get(['id']);
            if ((new FiscalYearCloseOverlap)->exists($actor, $start, $end, lockForUpdate: true)) {
                throw new AccountingConflict('Fiscal year overlaps an existing close.',
                    reason: AccountingConflictReason::FiscalYearOverlap);
            }
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            (new OperationalReconciliationChartDependencies)->lockParticipation($actor->id);
            $charts = ChartAccount::ownedBy($actor)->whereIn('id', $chartIds)->orderBy('id')->lockForUpdate()->get();
            $headers = JournalEntry::ownedBy($actor)->whereBetween('entry_date', [$start, $end])->orderBy('id')->lockForUpdate()->get();
            $manifest = fn ($rows) => $rows->map(fn ($row) => [(string) $row->id, $row->status, $row->entry_date->toDateString()])->all();
            if ($manifest($headers) !== $manifest($candidates)) {
                throw new AccountingConflict('Fiscal source headers changed; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($headers->contains(fn ($header) => $header->status === 'draft')) {
                throw new AccountingConflict('Draft journals must be resolved before closing the fiscal year.',
                    reason: AccountingConflictReason::FiscalYearDraftJournals);
            }
            $posted = $headers->where('status', 'posted');
            $lines = JournalLine::ownedBy($actor)->whereIn('journal_entry_id', $posted->modelKeys())
                ->orderBy('journal_entry_id')->orderBy('id')->lockForUpdate()->get();
            if (array_map('strval', $lines->pluck('chart_account_id')->unique()->sort()->values()->all()) !== array_map('strval', $sourceChartIds)) {
                throw new AccountingConflict('Fiscal source charts changed; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if (OpeningBalanceBatch::ownedBy($actor)->where('status', 'draft')->whereBetween('opening_date', [$start, $end])
                ->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) {
                throw new AccountingConflict('Draft opening balances must be resolved before closing the fiscal year.',
                    reason: AccountingConflictReason::FiscalYearDraftOpeningBalances);
            }
            foreach ($posted as $journal) {
                if ($journal->currency !== $input['currency']) {
                    throw new AccountingConflict('Posted journal currency does not match the fiscal-year currency.',
                        reason: AccountingConflictReason::FiscalYearCurrencyMismatch);
                }
            }
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

            $accounts = $charts->filter(fn ($chart) => in_array($chart->id, $sourceChartIds)
                && in_array($chart->type, ['revenue', 'expense'], true));
            $retained = $charts->firstWhere('id', (int) $input['retained_earnings_account_id']);
            if ($retained === null || ! $retained->is_active || $retained->type !== 'equity') {
                throw new AccountingConflict('Retained earnings account must be an owned, active equity account.',
                    reason: AccountingConflictReason::FiscalYearRetainedEarningsAccount);
            }

            try {
                $closingLines = (new FiscalYearProfitLoss)->closingLines($lines, $accounts, $retained->id);
            } catch (InvalidArgumentException $exception) {
                throw new AccountingConflict('Fiscal-year closing amount exceeds DECIMAL(15,2).', 0, $exception);
            }
            $closedAt = now();
            $journal = null;
            $createdSources = [];
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
                    $createdSources[] = $line->getAttributes();
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

            // All generated rows are transaction-owned before evidence locks.
            // Consuming reviewed source journals does NOT establish relevance.
            $generatedChartIds = array_values(array_unique(array_column($createdSources, 'chart_account_id')));
            $review = (new OperationalReconciliationHistoryDependencies)->lockBoundReview($actor->id, $integration, [
                'journal_ids' => $journal === null ? [] : [$journal->id],
                'chart_ids' => $generatedChartIds, 'lines' => $createdSources,
            ]);
            $relevant = $review !== null && array_intersect(array_map('strval', $generatedChartIds), array_map('strval', $review['chart_ids'])) !== [];
            $changes = $relevant ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            return $close->load('journalEntry.lines');
        }, 3);
    }
}
