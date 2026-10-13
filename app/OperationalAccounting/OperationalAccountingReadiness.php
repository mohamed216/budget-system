<?php

namespace App\OperationalAccounting;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Advisory, nonlocking diagnostics only; this result is not authorization for
 * reconciling -> ready. Use Actions\MarkOperationalAccountingReady for the
 * authoritative READY transition and current, locked freshness validation.
 */
final class OperationalAccountingReadiness
{
    /** @return array{ready: bool, issues: array<string, list<int>>} */
    public function for(User $owner): array
    {
        $ownerId = $owner->id;
        $integration = DB::table('operational_accounting_integrations')->where('user_id', $ownerId)->first();
        $state = $integration->state ?? 'legacy';
        $strategy = $integration->historical_transaction_strategy ?? null;
        $highWater = $integration->transaction_high_water_id ?? null;

        // Only explicit scope rows select participants. Left joins preserve selected
        // but unmapped/missing records as diagnostics rather than dropping them.
        // Historical coverage is checked separately; it never adds scope rows.
        $accounts = DB::table('operational_accounting_account_scope as scope')
            ->leftJoin('accounts as account', function ($join) {
                $join->on('account.id', '=', 'scope.account_id')->on('account.user_id', '=', 'scope.user_id');
            })->leftJoin('operational_account_mappings as mapping', function ($join) {
                $join->on('mapping.account_id', '=', 'scope.account_id')->on('mapping.user_id', '=', 'scope.user_id');
            })
            ->leftJoin('chart_of_accounts as chart', function ($join) {
                $join->on('chart.id', '=', 'mapping.chart_account_id')->on('chart.user_id', '=', 'mapping.user_id');
            })->where('scope.user_id', $ownerId)->orderBy('scope.account_id')
            ->get(['scope.account_id', 'account.id as operational_id', 'account.currency', 'mapping.account_id as mapped_id', 'chart.id as chart_account_id', 'chart.type', 'chart.is_active', 'chart.cash_role']);
        $categories = DB::table('operational_accounting_category_scope as scope')
            ->leftJoin('categories as category', function ($join) {
                $join->on('category.id', '=', 'scope.category_id')->on('category.user_id', '=', 'scope.user_id');
            })->leftJoin('operational_category_mappings as mapping', function ($join) {
                $join->on('mapping.category_id', '=', 'scope.category_id')->on('mapping.user_id', '=', 'scope.user_id');
            })->leftJoin('chart_of_accounts as chart', function ($join) {
                $join->on('chart.id', '=', 'mapping.chart_account_id')->on('chart.user_id', '=', 'mapping.user_id');
            })->where('scope.user_id', $ownerId)->orderBy('scope.category_id')
            ->get(['scope.category_id', 'category.id as operational_id', 'mapping.category_id as mapped_id', 'mapping.cash_flow_activity', 'mapping.reviewed_by', 'mapping.reviewed_at', 'category.type as category_type', 'chart.id as chart_account_id', 'chart.type as chart_type', 'chart.is_active', 'chart.cash_role']);
        $currency = $integration->accounting_currency ?? config('accounting.currency');

        $issues = [
            // Report chart IDs once, even when several scoped records share a target.
            // NULL means unreviewed; classification remains explicit, never inferred.
            'unreviewed_chart_cash_role_ids' => $accounts->concat($categories)
                ->filter(fn ($record) => $record->chart_account_id !== null && $record->cash_role === null)
                ->pluck('chart_account_id')->unique()->sort()->values()->all(),
            'invalid_account_scope_ids' => $accounts->whereNull('operational_id')->pluck('account_id')->all(),
            'missing_account_mapping_ids' => $accounts->whereNull('mapped_id')->pluck('account_id')->all(),
            'invalid_account_mapping_ids' => $accounts->filter(fn ($account) => $account->mapped_id !== null
                && (! $account->is_active || $account->type !== 'asset'))->pluck('account_id')->all(),
            'invalid_category_scope_ids' => $categories->whereNull('operational_id')->pluck('category_id')->all(),
            'missing_category_mapping_ids' => $categories->whereNull('mapped_id')->pluck('category_id')->all(),
            'invalid_category_mapping_ids' => $categories->filter(fn ($category) => $category->mapped_id !== null
                && (! $category->is_active || ! in_array($category->category_type, ['income', 'expense'], true)
                    || ($category->category_type === 'income' && $category->chart_type !== 'revenue')
                    || ($category->category_type === 'expense' && $category->chart_type !== 'expense')))->pluck('category_id')->all(),
            'unreviewed_category_mapping_ids' => $categories->filter(fn ($category) => $category->mapped_id !== null
                && (! in_array($category->cash_flow_activity, ['operating', 'investing', 'financing'], true)
                    || $category->reviewed_by === null || $category->reviewed_at === null))->pluck('category_id')->all(),
            'unexpected_category_type_ids' => $categories->filter(fn ($category) => $category->operational_id !== null
                && ! in_array($category->category_type, ['income', 'expense'], true))->pluck('category_id')->all(),
            'currency_mismatch_account_ids' => $accounts->filter(fn ($account) => $account->operational_id !== null
                && $account->currency !== $currency)->pluck('account_id')->all(),
            'integration_currency_mismatch' => [],
            'unreconciled_transaction_ids' => [],
            'invalid_historical_transaction_ids' => [],
            'historical_transactions_missing_account_scope_ids' => [],
            'historical_transactions_missing_category_scope_ids' => [],
            'missing_integration_metadata' => [],
            'invalid_integration_state' => [],
            'invalid_historical_transaction_strategy' => [],
            'missing_transaction_high_water' => [],
            'missing_reconciliation_evidence' => [],
            'unreviewed_scope' => ($integration->scope_reviewed_at ?? null) === null ? [$ownerId] : [],
            'missing_active_metadata' => [],
            'inconsistent_integration_metadata' => [],
        ];
        if ($integration === null) {
            $issues['missing_integration_metadata'] = [$ownerId];
        } else {
            if (! in_array($state, ['legacy', 'reconciling', 'ready', 'active'], true)) {
                $issues['invalid_integration_state'] = [$ownerId];
            }
            if (! in_array($strategy, ['historical_backfill', 'balance_only'], true)) {
                $issues['invalid_historical_transaction_strategy'] = [$ownerId];
            }
            if ($highWater === null) {
                $issues['missing_transaction_high_water'] = [$ownerId];
            }
            if (trim($integration->reconciliation_reference ?? '') === '' || $integration->reconciled_at === null) {
                $issues['missing_reconciliation_evidence'] = [$ownerId];
            }
            if ($state === 'active' && ($integration->accounting_currency === null || $integration->activated_at === null)) {
                $issues['missing_active_metadata'] = [$ownerId];
            }
            if ($state === 'active' && $integration->activated_at !== null) {
                foreach ([$integration->reconciled_at, $integration->scope_reviewed_at] as $evidenceTime) {
                    // TIMESTAMP scope review and DATETIME(6) reconciliation may
                    // have different string precision; compare actual instants.
                    if ($evidenceTime !== null && new \DateTimeImmutable($integration->activated_at) < new \DateTimeImmutable($evidenceTime)) {
                        $issues['inconsistent_integration_metadata'] = [$ownerId];
                    }
                }
            }
        }
        if ($integration !== null && $integration->accounting_currency !== null
            && $integration->accounting_currency !== config('accounting.currency')) {
            $issues['integration_currency_mismatch'] = [$ownerId];
        }
        if ($strategy === 'historical_backfill' && $highWater !== null) {
            // Income/expense rows are backfill candidates. Date/period eligibility and
            // accounting decisions remain in the reviewed evidence and controlled
            // backfill workflow; this inventory never silently exempts unlinked rows.
            // Valid existing links are guaranteed by the Slice 1 FK/posted-link guards.
            $historical = DB::table('transactions as transaction')->where('transaction.user_id', $ownerId)
                ->where('transaction.id', '<=', $highWater);
            $issues['invalid_historical_transaction_ids'] = (clone $historical)->get(['id', 'type'])
                ->filter(fn ($transaction) => ! in_array($transaction->type, ['income', 'expense'], true))->pluck('id')->all();
            $historical->whereIn('transaction.type', ['income', 'expense']);
            $issues['historical_transactions_missing_account_scope_ids'] = (clone $historical)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('operational_accounting_account_scope as scope')
                    ->whereColumn('scope.account_id', 'transaction.account_id')->where('scope.user_id', $ownerId))
                ->orderBy('transaction.id')->pluck('transaction.id')->all();
            $issues['historical_transactions_missing_category_scope_ids'] = (clone $historical)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('operational_accounting_category_scope as scope')
                    ->whereColumn('scope.category_id', 'transaction.category_id')->where('scope.user_id', $ownerId))
                ->orderBy('transaction.id')->pluck('transaction.id')->all();
            $issues['unreconciled_transaction_ids'] = $historical
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('operational_transaction_journals as link')
                    ->whereColumn('link.transaction_id', 'transaction.id')->where('link.user_id', $ownerId))
                ->orderBy('transaction.id')->pluck('transaction.id')->all();
        }

        // Legacy/reconciling report diagnostics without claiming readiness or
        // transitioning state. A reviewed empty scope is valid for balance_only.
        return ['ready' => in_array($state, ['ready', 'active'], true)
            && collect($issues)->every(fn (array $issue) => $issue === []), 'issues' => $issues];
    }
}
