<?php

namespace Tests\Concerns;

/** Database-free normalized authoritative fixture, separate from structural tests. */
trait BuildsReconciliationCompletionSnapshots
{
    private function completionSnapshot(string $strategy = 'balance_only'): array
    {
        $review = ['id' => 1, 'user_id' => 1, 'reference' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'historical_transaction_strategy' => $strategy, 'accounting_currency' => 'SAR', 'transaction_high_water_id' => 1,
            'scope_reviewed_at' => '2026-10-10 09:00:00', 'supersedes_review_id' => null,
            'balance_only_disclosure_code' => $strategy === 'balance_only' ? 'balance_only_legacy_history_v1' : null,
            'balance_only_disclosure_text' => $strategy === 'balance_only' ? 'Earlier income statement is incomplete.' : null,
            'created_at' => '2026-10-10 09:00:00.000000', 'reviewed_by' => null, 'completed_at' => null];
        $s = ['owner_id' => 1, 'currency' => 'SAR', 'review' => $review, 'predecessor' => null,
            'gate' => ['user_id' => 1, 'state' => 'reconciling', 'historical_transaction_strategy' => $strategy,
                'accounting_currency' => 'SAR', 'transaction_high_water_id' => 1, 'scope_reviewed_at' => $review['scope_reviewed_at'],
                'reconciliation_reference' => null, 'reconciled_at' => null], 'sources' => [], 'evidence' => []];
        foreach (['accounting_periods', 'fiscal_year_closes', 'accounts', 'categories', 'operational_accounting_integrations',
            'operational_accounting_account_scope', 'operational_accounting_category_scope', 'operational_account_mappings',
            'operational_category_mappings', 'chart_of_accounts', 'transactions', 'journal_entries', 'journal_lines',
            'journal_line_allocations', 'cash_flow_journal_completions', 'operational_transaction_journals',
            'opening_balance_batches', 'opening_balance_lines'] as $table) {
            $s['sources'][$table] = [];
        }
        $s['sources']['accounts'] = [['id' => 1, 'user_id' => 1, 'balance' => '100.00', 'currency' => 'SAR']];
        $s['sources']['categories'] = [['id' => 2, 'user_id' => 1, 'type' => 'income']];
        $s['sources']['operational_accounting_account_scope'] = [['account_id' => 1, 'user_id' => 1]];
        $s['sources']['operational_accounting_category_scope'] = [['category_id' => 2, 'user_id' => 1]];
        $s['sources']['operational_account_mappings'] = [['account_id' => 1, 'user_id' => 1, 'chart_account_id' => 10]];
        $s['sources']['operational_category_mappings'] = [['category_id' => 2, 'user_id' => 1, 'chart_account_id' => 12,
            'cash_flow_activity' => 'operating', 'reviewed_by' => 1, 'reviewed_at' => '2026-10-10 09:00:00']];
        foreach ([10 => 'asset', 11 => 'equity', 12 => 'revenue'] as $id => $type) {
            $s['sources']['chart_of_accounts'][] = ['id' => $id, 'user_id' => 1, 'type' => $type, 'cash_role' => 'non_cash', 'is_active' => 1];
        }
        $s['sources']['transactions'] = [['id' => 1, 'user_id' => 1, 'account_id' => 1, 'category_id' => 2,
            'type' => 'income', 'amount' => '100.00', 'date' => '2026-10-06']];
        $s['sources']['journal_entries'] = [['id' => 20, 'user_id' => 1, 'status' => 'posted', 'currency' => 'SAR',
            'entry_date' => '2026-10-06', 'posted_at' => '2026-10-10 09:00:00', 'reversal_of_id' => null]];
        $s['sources']['journal_lines'] = [
            ['id' => 30, 'user_id' => 1, 'journal_entry_id' => 20, 'chart_account_id' => 10, 'line_number' => 1, 'debit' => '100.00', 'credit' => '0.00'],
            ['id' => 31, 'user_id' => 1, 'journal_entry_id' => 20, 'chart_account_id' => $strategy === 'historical_backfill' ? 12 : 11,
                'line_number' => 2, 'debit' => '0.00', 'credit' => '100.00'],
        ];
        if ($strategy === 'historical_backfill') {
            $s['sources']['operational_transaction_journals'] = [['transaction_id' => 1, 'user_id' => 1,
                'journal_entry_id' => 20, 'account_id' => 1, 'category_id' => 2]];
        }
        $base = ['review_id' => 1, 'user_id' => 1];
        $s['evidence']['charts'] = [
            $base + ['chart_account_id' => 10, 'type_snapshot' => 'asset', 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1],
            $base + ['chart_account_id' => 12, 'type_snapshot' => 'revenue', 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1],
        ];
        $s['evidence']['accounts'] = [$base + ['account_id' => 1, 'chart_account_id' => 10,
            'stored_balance_snapshot' => '100.00', 'currency_snapshot' => 'SAR', 'rationale' => null]];
        $s['evidence']['categories'] = [$base + ['category_id' => 2, 'category_type_snapshot' => 'income',
            'chart_account_id' => 12, 'cash_flow_activity' => 'operating']];
        $s['evidence']['journals'] = [$base + ['journal_entry_id' => 20]];
        $s['evidence']['attributions'] = [$base + ['id' => 1, 'journal_entry_id' => 20, 'journal_line_id' => 30,
            'target_kind' => 'account', 'account_id' => 1, 'purpose' => $strategy === 'historical_backfill' ? 'existing_history' : 'opening_balance',
            'amount' => '100.00', 'rationale' => 'Explicitly reviewed accounting basis.']];

        return $s;
    }

    public static function invalidReconciliationSnapshots(): array
    {
        $cases = [
            'bound gate' => [['gate', 'reconciliation_reference'], 'already-bound'],
            'ready gate' => [['gate', 'state'], 'ready'],
            'completed review' => [['review', 'completed_at'], '2026-10-10 10:00:00.123456'],
            'strategy mismatch' => [['gate', 'historical_transaction_strategy'], 'historical_backfill'],
            'currency mismatch' => [['gate', 'accounting_currency'], 'USD'],
            'boundary mismatch' => [['gate', 'transaction_high_water_id'], 0],
            'scope time mismatch' => [['gate', 'scope_reviewed_at'], '2026-10-10 09:00:01'],
            'unreviewed scope' => [['gate', 'scope_reviewed_at'], null],
            'stale account mapping' => [['sources', 'operational_account_mappings', 0, 'chart_account_id'], 11],
            'stale category mapping' => [['sources', 'operational_category_mappings', 0, 'chart_account_id'], 11],
            'activity changed' => [['sources', 'operational_category_mappings', 0, 'cash_flow_activity'], 'investing'],
            'mapping reviewer missing' => [['sources', 'operational_category_mappings', 0, 'reviewed_by'], null],
            'role changed' => [['sources', 'chart_of_accounts', 0, 'cash_role'], 'cash'],
            'role unreviewed' => [['sources', 'chart_of_accounts', 0, 'cash_role'], null],
            'inactive chart' => [['sources', 'chart_of_accounts', 0, 'is_active'], 0],
            'wrong type' => [['sources', 'chart_of_accounts', 0, 'type'], 'liability'],
            'foreign chart' => [['sources', 'chart_of_accounts', 0, 'user_id'], 2],
            'balance changed' => [['sources', 'accounts', 0, 'balance'], '100.01'],
            'account currency changed' => [['sources', 'accounts', 0, 'currency'], 'USD'],
            'unexpected category' => [['sources', 'categories', 0, 'type'], 'transfer'],
            'post boundary activity' => [['sources', 'transactions', 0, 'id'], 2],
            'draft journal' => [['sources', 'journal_entries', 0, 'status'], 'draft'],
            'foreign journal' => [['sources', 'journal_entries', 0, 'user_id'], 2],
            'wrong journal currency' => [['sources', 'journal_entries', 0, 'currency'], 'USD'],
            'unbalanced journal' => [['sources', 'journal_lines', 1, 'credit'], '99.99'],
            'two sided line' => [['sources', 'journal_lines', 0, 'credit'], '0.01'],
            'undercoverage' => [['evidence', 'attributions', 0, 'amount'], '99.99'],
            'overcoverage' => [['evidence', 'attributions', 0, 'amount'], '100.01'],
            'wrong attribution target' => [['evidence', 'attributions', 0, 'account_id'], 2],
            'blank rationale' => [['evidence', 'attributions', 0, 'rationale'], '  '],
            'invalid purpose' => [['evidence', 'attributions', 0, 'purpose'], 'historical_transaction'],
            'blank disclosure' => [['review', 'balance_only_disclosure_text'], '  '],
            'wrong disclosure code' => [['review', 'balance_only_disclosure_code'], 'other'],
            'missing supersession' => [['review', 'supersedes_review_id'], 2],
        ];

        return array_map(fn ($case) => $case, $cases);
    }
}
