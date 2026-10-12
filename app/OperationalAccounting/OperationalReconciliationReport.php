<?php

namespace App\OperationalAccounting;

use App\Accounting\DecimalAmount;
use App\Accounting\SignedDecimalAmount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Advisory inventory only: no reconciliation approval, opening authority, or inferred scope. */
final class OperationalReconciliationReport
{
    /** Amounts are exact decimal strings; IDs/counts are integers. Boundary zero means empty history. */
    public function for(User $owner, int $transactionHighWaterId): array
    {
        if (! $owner->exists || $owner->getKey() === null || $owner->getKey() <= 0) {
            throw new InvalidArgumentException('Reconciliation inventory requires a persisted owner with a valid primary key.');
        }

        if ($transactionHighWaterId < 0) {
            throw new InvalidArgumentException('The explicit transaction boundary must be nonnegative.');
        }

        // Non-locking reads share MySQL's repeatable-read transaction snapshot. This
        // preview is still advisory; a future approval action must revalidate facts.
        return DB::transaction(fn () => $this->inventory($owner, $transactionHighWaterId));
    }

    private function inventory(User $owner, int $boundary): array
    {
        $ownerId = $owner->id;
        $accounts = DB::table('accounts')->where('user_id', $ownerId)->orderBy('id')->get()->keyBy('id');
        $categories = DB::table('categories')->where('user_id', $ownerId)->orderBy('id')->get()->keyBy('id');
        $transactions = DB::table('transactions')->where('user_id', $ownerId)->orderBy('id')->get();
        $accountMappings = DB::table('operational_account_mappings')->where('user_id', $ownerId)->orderBy('account_id')->get()->keyBy('account_id');
        $categoryMappings = DB::table('operational_category_mappings')->where('user_id', $ownerId)->orderBy('category_id')->get()->keyBy('category_id');
        $accountScope = DB::table('operational_accounting_account_scope')->where('user_id', $ownerId)->orderBy('account_id')->pluck('account_id');
        $categoryScope = DB::table('operational_accounting_category_scope')->where('user_id', $ownerId)->orderBy('category_id')->pluck('category_id');
        $chartIds = $accountMappings->pluck('chart_account_id')->concat($categoryMappings->pluck('chart_account_id'))->unique();
        $charts = DB::table('chart_of_accounts')->where('user_id', $ownerId)->whereIn('id', $chartIds)->orderBy('id')->get()->keyBy('id');
        $selectedChartIds = $accountMappings->only($accountScope->all())->pluck('chart_account_id')
            ->concat($categoryMappings->only($categoryScope->all())->pluck('chart_account_id'))->unique()->sort()->values();
        $historical = $transactions->filter(fn ($row) => $row->id <= $boundary)->values();
        $later = $transactions->filter(fn ($row) => $row->id > $boundary)->values();
        $links = DB::table('operational_transaction_journals')->where('user_id', $ownerId)
            ->whereIn('transaction_id', $historical->pluck('id'))->orderBy('transaction_id')->get()->keyBy('transaction_id');

        // Include scoped chart history and linked headers needed for link diagnostics.
        // All parent/child reads independently enforce owner isolation.
        $journals = DB::table('journal_entries')->where('user_id', $ownerId)
            ->where(function ($query) use ($ownerId, $selectedChartIds, $links) {
                $query->whereIn('id', $links->pluck('journal_entry_id'))->orWhereExists(fn ($lines) => $lines
                    ->selectRaw('1')->from('journal_lines')->whereColumn('journal_lines.journal_entry_id', 'journal_entries.id')
                    ->where('journal_lines.user_id', $ownerId)->whereIn('journal_lines.chart_account_id', $selectedChartIds));
            })->orderBy('id')->get()->keyBy('id');
        $lines = DB::table('journal_lines')->where('user_id', $ownerId)->whereIn('journal_entry_id', $journals->keys())
            ->orderBy('journal_entry_id')->orderBy('line_number')->orderBy('id')->get();
        $openings = DB::table('opening_balance_batches')->where('user_id', $ownerId)->where('status', 'posted')
            ->whereIn('journal_entry_id', $journals->keys())->orderBy('id')->get()->keyBy('journal_entry_id');
        $closes = DB::table('fiscal_year_closes')->where('user_id', $ownerId)
            ->whereIn('journal_entry_id', $journals->keys())->orderBy('id')->get()->keyBy('journal_entry_id');
        $reversals = DB::table('journal_entries')->where('user_id', $ownerId)->where('status', 'posted')
            ->whereIn('reversal_of_id', $journals->keys())->orderBy('id')->get()->groupBy('reversal_of_id');
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $ownerId)->first();

        $accountRows = $accounts->map(function ($account) use ($transactions, $boundary, $accountScope) {
            $rows = $transactions->where('account_id', $account->id)->values();
            $totals = $this->transactionTotals($rows);

            return [
                'account_id' => $account->id, 'stored_balance' => $account->balance, 'currency' => $account->currency,
                'selected' => $accountScope->contains($account->id), 'transactions' => $totals,
                // All recorded owner/account movement, not merely the historical subset.
                // A computed candidate is never authorization to create an opening.
                'starting_balance_candidate' => SignedDecimalAmount::fromAggregateString($account->balance)
                    ->add(SignedDecimalAmount::fromAggregateString($totals['net'])->negate())->toDecimal(),
                'historical' => $this->transactionTotals($rows->filter(fn ($row) => $row->id <= $boundary)->values()),
                'post_snapshot' => $this->transactionTotals($rows->filter(fn ($row) => $row->id > $boundary)->values()),
            ];
        })->values()->all();

        $scopedAccounts = $accountScope->map(function ($id) use ($accounts, $accountMappings, $charts) {
            $mapping = $accountMappings->get($id);
            $chart = $mapping === null ? null : $charts->get($mapping->chart_account_id);

            return ['account_id' => $id, 'record_exists' => $accounts->has($id), 'mapping_missing' => $mapping === null,
                'chart' => $this->chartMetadata($chart), 'chart_type_compatible' => $chart !== null && $chart->type === 'asset'];
        })->all();
        $scopedCategories = $categoryScope->map(function ($id) use ($categories, $categoryMappings, $charts) {
            $category = $categories->get($id);
            $mapping = $categoryMappings->get($id);
            $chart = $mapping === null ? null : $charts->get($mapping->chart_account_id);
            $expected = match ($category->type ?? null) {
                'income' => 'revenue', 'expense' => 'expense', default => null
            };

            return ['category_id' => $id, 'record_exists' => $category !== null, 'category_type' => $category->type ?? null,
                'mapping_missing' => $mapping === null, 'chart' => $this->chartMetadata($chart),
                'chart_type_compatible' => $expected !== null && $chart !== null && $chart->type === $expected,
                'cash_flow_activity' => $mapping->cash_flow_activity ?? null, 'reviewed_by' => $mapping->reviewed_by ?? null,
                'reviewed_at' => $mapping->reviewed_at ?? null,
                'activity_reviewed' => $mapping !== null && in_array($mapping->cash_flow_activity, ['operating', 'investing', 'financing'], true)
                    && $mapping->reviewed_by !== null && $mapping->reviewed_at !== null];
        })->all();

        $chartRows = $charts->only($selectedChartIds->all())->sortKeys()->map(function ($chart) use ($lines, $journals, $openings, $closes, $reversals) {
            $postedLines = $lines->where('chart_account_id', $chart->id)
                ->filter(fn ($line) => $journals->get($line->journal_entry_id)?->status === 'posted');
            $debit = $this->sum($postedLines, 'debit');
            $credit = $this->sum($postedLines, 'credit');
            $debitNormal = in_array($chart->type, ['asset', 'expense'], true);
            $references = $postedLines->pluck('journal_entry_id')->unique()->sort()->map(function ($id) use ($journals, $openings, $closes, $reversals) {
                $journal = $journals->get($id);

                return ['journal_entry_id' => $id, 'entry_date' => $journal->entry_date, 'currency' => $journal->currency,
                    'reference' => $journal->reference, 'opening_balance_batch_id' => $openings->get($id)?->id,
                    'fiscal_year_close_id' => $closes->get($id)?->id, 'reversal_of_id' => $journal->reversal_of_id,
                    'reversed_by_journal_ids' => $reversals->get($id, collect())->pluck('id')->all()];
            })->values()->all();

            return $this->chartMetadata($chart) + ['posted_debit_total' => $debit, 'posted_credit_total' => $credit,
                'normal_balance_side' => $debitNormal ? 'debit' : 'credit',
                'signed_ledger_movement' => SignedDecimalAmount::difference($debitNormal ? $debit : $credit, $debitNormal ? $credit : $debit)->toDecimal(),
                'posted_journals' => $references];
        })->values()->all();

        $invalidLinks = [];
        foreach ($historical as $transaction) {
            $link = $links->get($transaction->id);
            if ($link === null) {
                continue;
            }
            $journal = $journals->get($link->journal_entry_id);
            $issues = [];
            if ($link->account_id !== $transaction->account_id || $link->category_id !== $transaction->category_id) {
                $issues[] = 'transaction_identity_mismatch';
            }
            if ($journal === null || $journal->status !== 'posted') {
                $issues[] = 'missing_owned_posted_journal';
            } else {
                if ($journal->entry_date !== $transaction->date || $journal->currency !== ($accounts->get($transaction->account_id)?->currency)) {
                    $issues[] = 'date_or_currency_mismatch';
                }
                if ($journal->reversal_of_id !== null || $openings->has($journal->id) || $closes->has($journal->id) || $reversals->has($journal->id)) {
                    $issues[] = 'special_or_reversed_journal';
                }
                $asset = $accountMappings->get($transaction->account_id)?->chart_account_id;
                $counterpart = $categoryMappings->get($transaction->category_id)?->chart_account_id;
                $journalLines = $lines->where('journal_entry_id', $journal->id);
                $debitTarget = $transaction->type === 'income' ? $asset : $counterpart;
                $creditTarget = $transaction->type === 'income' ? $counterpart : $asset;
                if (! in_array($transaction->type, ['income', 'expense'], true) || $asset === null || $counterpart === null
                    || $journalLines->count() !== 2
                    || $journalLines->filter(fn ($line) => $line->chart_account_id === $debitTarget && $line->debit === $transaction->amount && $line->credit === '0.00')->count() !== 1
                    || $journalLines->filter(fn ($line) => $line->chart_account_id === $creditTarget && $line->credit === $transaction->amount && $line->debit === '0.00')->count() !== 1) {
                    $issues[] = 'missing_mapping_or_journal_line_mismatch';
                }
            }
            if ($issues !== []) {
                $invalidLinks[] = ['transaction_id' => $transaction->id, 'journal_entry_id' => $journal?->id, 'issues' => $issues];
            }
        }
        $linkedIds = $historical->filter(fn ($row) => $links->has($row->id))->pluck('id')->all();
        $unlinkedIds = $historical->reject(fn ($row) => $links->has($row->id))->pluck('id')->all();
        // Existing mappings can reveal shared attribution, but never select scope.
        $shared = $accountMappings->only($accounts->keys()->all())->groupBy('chart_account_id')->sortKeys()
            ->filter(fn ($group, $id) => $group->count() > 1 && $charts->has($id))
            ->map(fn ($group, $id) => ['chart_account_id' => (int) $id, 'account_ids' => $group->pluck('account_id')->sort()->values()->all(),
                'selected_account_ids' => $group->pluck('account_id')->intersect($accountScope)->sort()->values()->all(),
                'warning' => 'shared_chart_target_requires_reviewed_attribution'])->values()->all();

        return [
            'owner_id' => $ownerId, 'transaction_high_water_id' => $boundary, 'advisory' => true,
            'accounts' => $accountRows,
            'historical' => $this->transactionTotals($historical) + ['links' => [
                'linked_count' => count($linkedIds), 'linked_transaction_ids' => $linkedIds,
                'unlinked_count' => count($unlinkedIds), 'unlinked_transaction_ids' => $unlinkedIds, 'invalid_links' => $invalidLinks]],
            'post_snapshot' => $this->transactionTotals($later), 'snapshot_has_later_legacy_activity' => $later->isNotEmpty(),
            'scope' => ['scope_reviewed_at' => $gate->scope_reviewed_at ?? null, 'accounts' => $scopedAccounts, 'categories' => $scopedCategories],
            'chart_accounts' => $chartRows, 'shared_chart_targets' => $shared,
            'diagnostics' => [
                'invalid_owned_parent_transaction_ids' => $transactions->filter(fn ($row) => ! $accounts->has($row->account_id) || ! $categories->has($row->category_id))->pluck('id')->all(),
                'unexpected_transaction_type_ids' => $transactions->reject(fn ($row) => in_array($row->type, ['income', 'expense'], true))->pluck('id')->all(),
            ],
        ];
    }

    private function chartMetadata(?object $chart): ?array
    {
        return $chart === null ? null : ['chart_account_id' => $chart->id, 'type' => $chart->type,
            'active' => (bool) $chart->is_active, 'cash_role' => $chart->cash_role, 'cash_role_reviewed' => $chart->cash_role !== null];
    }

    private function sum(Collection $rows, string $column): string
    {
        $total = DecimalAmount::fromAggregateString('0.00');
        foreach ($rows as $row) {
            $total = $total->add(DecimalAmount::fromAggregateString($row->{$column}));
        }

        return $total->toDecimal();
    }

    private function transactionTotals(Collection $rows): array
    {
        $income = $this->sum($rows->whereStrict('type', 'income'), 'amount');
        $expense = $this->sum($rows->whereStrict('type', 'expense'), 'amount');

        return ['count' => $rows->count(), 'transaction_ids' => $rows->pluck('id')->values()->all(),
            'income_total' => $income, 'expense_total' => $expense, 'net' => SignedDecimalAmount::difference($income, $expense)->toDecimal(),
            'min_transaction_id' => $rows->first()?->id, 'max_transaction_id' => $rows->last()?->id];
    }
}
