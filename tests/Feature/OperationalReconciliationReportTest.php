<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseFiscalYear;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Models\User;
use App\OperationalAccounting\Actions\MapOperationalAccount;
use App\OperationalAccounting\Actions\MapOperationalCategory;
use App\OperationalAccounting\OperationalReconciliationReport;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalReconciliationReportTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private int $account;

    private int $income;

    private int $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = $this->account($this->owner, '20500.00');
        $this->income = $this->category($this->owner, 'income');
        $this->expense = $this->category($this->owner, 'expense');
    }

    private function account(User $owner, string $balance = '0.00'): int
    {
        return DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'name' => 'Operational',
            'type' => 'bank', 'balance' => $balance, 'currency' => config('accounting.currency')]);
    }

    private function category(User $owner, string $type): int
    {
        return DB::table('categories')->insertGetId(['user_id' => $owner->id, 'name' => 'Category', 'type' => $type]);
    }

    private function transaction(string $amount, string $type = 'income', ?User $owner = null, ?int $account = null, ?int $category = null): int
    {
        return DB::table('transactions')->insertGetId(['user_id' => ($owner ?? $this->owner)->id,
            'account_id' => $account ?? $this->account, 'category_id' => $category ?? ($type === 'income' ? $this->income : $this->expense),
            'amount' => $amount, 'type' => $type, 'date' => '2026-10-06']);
    }

    private function chart(string $code, string $type, ?string $role = 'non_cash', ?User $owner = null): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => ($owner ?? $this->owner)->id,
            'code' => $code, 'name' => $code, 'type' => $type, 'is_active' => true, 'cash_role' => $role]);
    }

    private function scope(array $accounts = [], array $categories = [], ?User $owner = null): void
    {
        foreach ($accounts as $id) {
            DB::table('operational_accounting_account_scope')->insert(['account_id' => $id, 'user_id' => ($owner ?? $this->owner)->id]);
        }
        foreach ($categories as $id) {
            DB::table('operational_accounting_category_scope')->insert(['category_id' => $id, 'user_id' => ($owner ?? $this->owner)->id]);
        }
    }

    private function journal(int $debit, int $credit, string $amount, bool $posted = true, ?User $owner = null): int
    {
        $owner ??= $this->owner;
        $id = DB::table('journal_entries')->insertGetId(['user_id' => $owner->id,
            'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'), 'reference' => 'Reviewed fixture']);
        DB::table('journal_lines')->insert([
            ['user_id' => $owner->id, 'journal_entry_id' => $id, 'chart_account_id' => $debit, 'line_number' => 1, 'debit' => $amount, 'credit' => '0.00'],
            ['user_id' => $owner->id, 'journal_entry_id' => $id, 'chart_account_id' => $credit, 'line_number' => 2, 'debit' => '0.00', 'credit' => $amount],
        ]);
        if ($posted) {
            DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
        }

        return $id;
    }

    private function report(int $boundary): array
    {
        return (new OperationalReconciliationReport)->for($this->owner, $boundary);
    }

    public function test_exact_totals_and_starting_candidate_keep_the_explicit_boundary_separate(): void
    {
        $income = $this->transaction('500.01');
        $expense = $this->transaction('0.02', 'expense');
        $later = $this->transaction('0.03');
        $report = $this->report($expense);
        $this->assertTrue($report['advisory']);
        $this->assertSame($expense, $report['transaction_high_water_id']);
        $this->assertSame(2, $report['historical']['count']);
        $this->assertSame([$income, $expense], $report['historical']['transaction_ids']);
        $this->assertSame('500.01', $report['historical']['income_total']);
        $this->assertSame('0.02', $report['historical']['expense_total']);
        $this->assertSame('499.99', $report['historical']['net']);
        $this->assertSame($income, $report['historical']['min_transaction_id']);
        $this->assertSame($expense, $report['historical']['max_transaction_id']);
        $this->assertSame([$later], $report['post_snapshot']['transaction_ids']);
        $this->assertSame('0.03', $report['post_snapshot']['net']);
        $this->assertTrue($report['snapshot_has_later_legacy_activity']);
        $account = $report['accounts'][0];
        $this->assertSame($this->account, $account['account_id']);
        $this->assertSame('20500.00', $account['stored_balance']);
        $this->assertSame(config('accounting.currency'), $account['currency']);
        $this->assertSame('500.04', $account['transactions']['income_total']);
        $this->assertSame('500.02', $account['transactions']['net']);
        $this->assertSame('19999.98', $account['starting_balance_candidate']);
        $this->assertSame('499.99', $account['historical']['net']);
        $this->assertSame('0.03', $account['post_snapshot']['net']);
        $this->assertFalse($account['selected']);
        $this->assertSame($report, $this->report($expense));
        $empty = $this->report(0);
        $this->assertSame(0, $empty['historical']['count']);
        $this->assertNull($empty['historical']['min_transaction_id']);
        $this->assertNull($empty['historical']['max_transaction_id']);
        $this->assertSame('0.00', $empty['historical']['net']);
        $this->assertSame(3, $empty['post_snapshot']['count']);
    }

    public function test_negative_balances_and_aggregates_exceeding_single_row_money_limits_remain_exact(): void
    {
        DB::table('accounts')->where('id', $this->account)->update(['balance' => '-0.01']);
        $this->transaction('9999999999999.99', 'expense');
        $boundary = $this->transaction('9999999999999.99', 'expense');
        $report = $this->report($boundary);
        $this->assertSame('19999999999999.98', $report['historical']['expense_total']);
        $this->assertSame('-19999999999999.98', $report['historical']['net']);
        $this->assertSame('-0.01', $report['accounts'][0]['stored_balance']);
        $this->assertSame('19999999999999.97', $report['accounts'][0]['starting_balance_candidate']);
        $other = $this->account($this->owner, '-5.00');
        $this->transaction('0.01', account: $other);
        $accounts = collect($this->report($boundary)['accounts'])->keyBy('account_id');
        $this->assertSame('-5.01', $accounts[$other]['starting_balance_candidate']);
        $this->assertSame('0.00', $accounts[$other]['historical']['net']);
        $this->assertSame('0.01', $accounts[$other]['post_snapshot']['net']);
    }

    public function test_scope_and_review_inventory_never_infers_participation(): void
    {
        $asset = $this->chart('ASSET', 'asset', null);
        $revenue = $this->chart('REVENUE', 'revenue');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income, $revenue, 'investing');
        $boundary = $this->transaction('500.00');
        $this->assertSame([], $this->report($boundary)['scope']['accounts']);
        $this->assertSame([], $this->report($boundary)['scope']['categories']);
        $this->assertSame([], $this->report($boundary)['chart_accounts']);
        $missingAccount = $this->account($this->owner);
        $this->scope([$this->account, $missingAccount], [$this->income, $this->expense]);
        DB::table('chart_of_accounts')->where('id', $asset)->update(['is_active' => false]);
        $report = $this->report($boundary);
        $accounts = collect($report['scope']['accounts'])->keyBy('account_id');
        $categories = collect($report['scope']['categories'])->keyBy('category_id');
        $this->assertFalse($accounts[$this->account]['mapping_missing']);
        $this->assertTrue($accounts[$this->account]['chart_type_compatible']);
        $this->assertFalse($accounts[$this->account]['chart']['active']);
        $this->assertNull($accounts[$this->account]['chart']['cash_role']);
        $this->assertFalse($accounts[$this->account]['chart']['cash_role_reviewed']);
        $this->assertTrue($accounts[$missingAccount]['mapping_missing']);
        $this->assertNull($accounts[$missingAccount]['chart']);
        $this->assertTrue($categories[$this->income]['chart_type_compatible']);
        $this->assertTrue($categories[$this->income]['chart']['cash_role_reviewed']);
        $this->assertSame('non_cash', $categories[$this->income]['chart']['cash_role']);
        $this->assertSame('investing', $categories[$this->income]['cash_flow_activity']);
        $this->assertSame($this->owner->id, $categories[$this->income]['reviewed_by']);
        $this->assertNotNull($categories[$this->income]['reviewed_at']);
        $this->assertTrue($categories[$this->income]['activity_reviewed']);
        $this->assertTrue($categories[$this->expense]['mapping_missing']);
        $this->assertFalse($categories[$this->expense]['activity_reviewed']);

        DB::table('operational_category_mappings')->insert(['category_id' => $this->expense, 'user_id' => $this->owner->id,
            'chart_account_id' => $this->chart('WRONG-EXPENSE', 'revenue', null)]);
        $category = collect($this->report($boundary)['scope']['categories'])->keyBy('category_id')[$this->expense];
        $this->assertFalse($category['mapping_missing']);
        $this->assertFalse($category['chart_type_compatible']);
        $this->assertFalse($category['activity_reviewed']);
        $this->assertFalse($category['chart']['cash_role_reviewed']);
    }

    public function test_posted_only_ledger_totals_use_chart_normal_balance_sides_and_include_special_references(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        $revenue = $this->chart('REVENUE', 'revenue');
        $equity = $this->chart('EQUITY', 'equity');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income, $revenue, 'operating');
        $this->scope([$this->account], [$this->income]);
        $batch = (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-01-01', config('accounting.currency'), [
            ['chart_account_id' => $asset, 'debit' => '20000.00', 'credit' => '0.00'],
            ['chart_account_id' => $equity, 'debit' => '0.00', 'credit' => '20000.00'],
        ]);
        $batch = (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
        $posted = $this->journal($asset, $revenue, '500.01');
        $draft = $this->journal($asset, $revenue, '999.99', false);
        // Keep the draft outside the closing interval: Phase 2 correctly refuses
        // a fiscal close while drafts in that interval remain unresolved.
        DB::table('journal_entries')->where('id', $draft)->update(['entry_date' => '2026-11-01']);
        $reversed = $this->journal($asset, $revenue, '0.01');
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07'));
        try {
            $reversal = (new ReverseJournalEntry)->execute($this->owner, $reversed);
        } finally {
            $this->travelBack();
        }
        $close = (new CloseFiscalYear)->execute($this->owner, '2026-01-01', '2026-10-31', config('accounting.currency'), $equity);
        $charts = collect($this->report(0)['chart_accounts'])->keyBy('chart_account_id');
        $this->assertSame('20500.02', $charts[$asset]['posted_debit_total']);
        $this->assertSame('0.01', $charts[$asset]['posted_credit_total']);
        $this->assertSame('20500.01', $charts[$asset]['signed_ledger_movement']);
        $this->assertSame('debit', $charts[$asset]['normal_balance_side']);
        $this->assertSame('credit', $charts[$revenue]['normal_balance_side']);
        $this->assertSame('0.00', $charts[$revenue]['signed_ledger_movement']);
        $references = collect($charts[$asset]['posted_journals'])->keyBy('journal_entry_id');
        $this->assertSame($batch->id, $references[$batch->journal_entry_id]['opening_balance_batch_id']);
        $this->assertSame($reversed, $references[$reversal->id]['reversal_of_id']);
        $this->assertSame([$reversal->id], $references[$reversed]['reversed_by_journal_ids']);
        $this->assertTrue($references->has($posted));
        $this->assertFalse($references->has($draft));
        $closingReferences = collect($charts[$revenue]['posted_journals'])->keyBy('journal_entry_id');
        $this->assertSame($close->id, $closingReferences[$close->journal_entry_id]['fiscal_year_close_id']);
    }

    public function test_historical_links_are_inventory_and_reversed_linked_history_is_diagnosed(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        $revenue = $this->chart('REVENUE', 'revenue');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income, $revenue, 'operating');
        $linked = $this->transaction('1.00');
        $unlinked = $this->transaction('2.00');
        $journal = $this->journal($asset, $revenue, '1.00');
        DB::table('operational_transaction_journals')->insert(['transaction_id' => $linked, 'journal_entry_id' => $journal,
            'user_id' => $this->owner->id, 'account_id' => $this->account, 'category_id' => $this->income]);
        $later = $this->transaction('3.00');
        $links = $this->report($unlinked)['historical']['links'];
        $this->assertSame(1, $links['linked_count']);
        $this->assertSame([$linked], $links['linked_transaction_ids']);
        $this->assertSame(1, $links['unlinked_count']);
        $this->assertSame([$unlinked], $links['unlinked_transaction_ids']);
        $this->assertNotContains($later, $links['unlinked_transaction_ids']);
        $this->assertSame([], $links['invalid_links']);
        (new ReverseJournalEntry)->execute($this->owner, $journal);
        $this->assertSame([['transaction_id' => $linked, 'journal_entry_id' => $journal,
            'issues' => ['special_or_reversed_journal']]], $this->report($unlinked)['historical']['links']['invalid_links']);
    }

    public function test_large_posted_expense_ledger_aggregates_are_exact_and_debit_normal(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        $expense = $this->chart('EXPENSE', 'expense');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->expense, $expense, 'operating');
        $this->scope([$this->account], [$this->expense]);
        $this->journal($expense, $asset, '9999999999999.99');
        $this->journal($expense, $asset, '9999999999999.99');
        $charts = collect($this->report(0)['chart_accounts'])->keyBy('chart_account_id');
        $this->assertSame('19999999999999.98', $charts[$expense]['posted_debit_total']);
        $this->assertSame('0.00', $charts[$expense]['posted_credit_total']);
        $this->assertSame('debit', $charts[$expense]['normal_balance_side']);
        $this->assertSame('19999999999999.98', $charts[$expense]['signed_ledger_movement']);
        $this->assertSame('-19999999999999.98', $charts[$asset]['signed_ledger_movement']);
    }

    public function test_reviewed_empty_scope_is_reported_without_selecting_existing_history_or_mappings(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $asset);
        $this->assertNull($this->report(0)['scope']['scope_reviewed_at']);
        DB::table('operational_accounting_integrations')->insert(['user_id' => $this->owner->id,
            'scope_reviewed_at' => '2026-10-10 10:00:00']);
        $report = $this->report(0);
        $this->assertSame('2026-10-10 10:00:00', $report['scope']['scope_reviewed_at']);
        $this->assertSame([], $report['scope']['accounts']);
        $this->assertSame([], $report['scope']['categories']);
        $this->assertSame([], $report['chart_accounts']);
        $this->assertFalse($report['accounts'][0]['selected']);
    }

    public function test_shared_chart_targets_warn_without_assigning_the_ledger_to_individual_accounts(): void
    {
        $otherAccount = $this->account($this->owner, '-1.00');
        $asset = $this->chart('SHARED', 'asset');
        $revenue = $this->chart('REVENUE', 'revenue');
        foreach ([$this->account, $otherAccount] as $id) {
            (new MapOperationalAccount)->execute($this->owner, $id, $asset);
        }
        $this->scope([$this->account]);
        $this->journal($asset, $revenue, '12.34');
        $report = $this->report(0);
        $this->assertSame([['chart_account_id' => $asset, 'account_ids' => [$this->account, $otherAccount],
            'selected_account_ids' => [$this->account], 'warning' => 'shared_chart_target_requires_reviewed_attribution']], $report['shared_chart_targets']);
        $this->assertCount(1, $report['chart_accounts']);
        $this->assertSame('12.34', $report['chart_accounts'][0]['signed_ledger_movement']);
        $this->assertSame([$this->account], array_column($report['scope']['accounts'], 'account_id'));
        foreach ($report['accounts'] as $account) {
            $this->assertArrayNotHasKey('ledger_balance', $account);
            $this->assertArrayNotHasKey('reconciled', $account);
        }
    }

    public function test_foreign_and_unassigned_records_never_affect_owner_inventory_or_totals(): void
    {
        $ownedTransaction = $this->transaction('0.01');
        $ownedAsset = $this->chart('OWNED-ASSET', 'asset');
        $ownedRevenue = $this->chart('OWNED-REVENUE', 'revenue');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $ownedAsset);
        (new MapOperationalCategory)->execute($this->owner, $this->income, $ownedRevenue, 'operating');
        $this->scope([$this->account], [$this->income]);
        $ownedJournal = $this->journal($ownedAsset, $ownedRevenue, '0.01');
        $other = User::factory()->create();
        $account = $this->account($other, '9999999999999.99');
        $category = $this->category($other, 'income');
        $foreignTransaction = $this->transaction('9999999999999.99', owner: $other, account: $account, category: $category);
        $asset = $this->chart('FOREIGN-ASSET', 'asset', owner: $other);
        $revenue = $this->chart('FOREIGN-REVENUE', 'revenue', owner: $other);
        (new MapOperationalAccount)->execute($other, $account, $asset);
        (new MapOperationalCategory)->execute($other, $category, $revenue, 'operating');
        $this->scope([$account], [$category], $other);
        $journal = $this->journal($asset, $revenue, '9999999999999.99', owner: $other);
        DB::table('operational_transaction_journals')->insert(['transaction_id' => $foreignTransaction, 'journal_entry_id' => $journal,
            'user_id' => $other->id, 'account_id' => $account, 'category_id' => $category]);
        $unassigned = DB::table('accounts')->insertGetId(['user_id' => null, 'name' => 'Unassigned', 'type' => 'bank', 'balance' => '999.99', 'currency' => config('accounting.currency')]);
        $unassignedCategory = DB::table('categories')->insertGetId(['user_id' => null, 'name' => 'Unassigned', 'type' => 'income']);
        DB::table('transactions')->insert(['user_id' => null, 'account_id' => $unassigned, 'category_id' => $unassignedCategory,
            'amount' => '999.99', 'type' => 'income', 'date' => '2026-10-06']);
        $report = $this->report($foreignTransaction);
        $this->assertSame([$this->account], array_column($report['accounts'], 'account_id'));
        $this->assertSame([$ownedTransaction], $report['historical']['transaction_ids']);
        $this->assertSame('0.01', $report['historical']['income_total']);
        $this->assertSame([$this->account], array_column($report['scope']['accounts'], 'account_id'));
        $this->assertSame([$this->income], array_column($report['scope']['categories'], 'category_id'));
        $this->assertSame([$ownedAsset, $ownedRevenue], array_column($report['chart_accounts'], 'chart_account_id'));
        $this->assertSame('0.01', $report['chart_accounts'][0]['posted_debit_total']);
        $this->assertSame('0.01', $report['chart_accounts'][1]['posted_credit_total']);
        $this->assertSame([$ownedJournal], array_column($report['chart_accounts'][0]['posted_journals'], 'journal_entry_id'));
        $this->assertSame([], $report['shared_chart_targets']);
        $this->assertSame(0, $report['historical']['links']['linked_count']);
        $this->assertNull(DB::table('accounts')->where('id', $unassigned)->value('user_id'));
    }

    public function test_report_executes_only_selects_and_leaves_all_financial_and_preparation_rows_unchanged(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        $revenue = $this->chart('REVENUE', 'revenue');
        (new MapOperationalAccount)->execute($this->owner, $this->account, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income, $revenue, 'operating');
        $this->scope([$this->account], [$this->income]);
        $boundary = $this->transaction('0.01');
        $this->journal($asset, $revenue, '0.01');
        DB::table('operational_accounting_integrations')->insert(['user_id' => $this->owner->id, 'state' => 'reconciling',
            'transaction_high_water_id' => 0, 'scope_reviewed_at' => null]);
        $tables = ['accounts', 'categories', 'transactions', 'chart_of_accounts', 'journal_entries', 'journal_lines',
            'operational_transaction_journals', 'operational_account_mappings', 'operational_category_mappings',
            'operational_accounting_integrations', 'operational_accounting_account_scope', 'operational_accounting_category_scope',
            'opening_balance_batches', 'fiscal_year_closes'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $report = $this->report($boundary);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
        }
        $this->assertSame($boundary, $report['transaction_high_water_id']);
        $this->assertNull($report['scope']['scope_reviewed_at']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', ltrim($query['query']));
            $this->assertDoesNotMatchRegularExpression('/for\s+(update|share)/i', $query['query']);
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        $this->assertSame(0, DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->value('transaction_high_water_id'));
    }

    public function test_unpersisted_or_invalid_owner_is_rejected_before_queries_without_returning_unassigned_data(): void
    {
        $account = DB::table('accounts')->insertGetId(['user_id' => null, 'name' => 'Unassigned',
            'type' => 'bank', 'balance' => '123.45', 'currency' => config('accounting.currency')]);
        $category = DB::table('categories')->insertGetId(['user_id' => null, 'name' => 'Unassigned', 'type' => 'income']);
        DB::table('transactions')->insert(['user_id' => null, 'account_id' => $account, 'category_id' => $category,
            'amount' => '0.01', 'type' => 'income', 'date' => '2026-10-06']);
        $before = [];
        foreach (['accounts', 'categories', 'transactions', 'users'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $unsaved = new User;
        $unsavedWithKey = new User;
        $unsavedWithKey->setAttribute('id', $this->owner->id);
        $missingKey = clone $this->owner;
        $missingKey->setAttribute('id', null);
        $invalidKey = clone $this->owner;
        $invalidKey->setAttribute('id', 0);
        $transactionLevel = DB::transactionLevel();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            foreach ([$unsaved, $unsavedWithKey, $missingKey, $invalidKey] as $owner) {
                $result = null;
                try {
                    $result = (new OperationalReconciliationReport)->for($owner, 0);
                    $this->fail('Inventory accepted an unpersisted or invalid owner.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame('Reconciliation inventory requires a persisted owner with a valid primary key.', $exception->getMessage());
                }
                $this->assertNull($result);
                $this->assertSame([], DB::connection()->getQueryLog());
                $this->assertSame($transactionLevel, DB::transactionLevel());
            }
        } finally {
            DB::connection()->disableQueryLog();
        }
        $this->assertFalse($unsaved->exists);
        $this->assertNull($unsaved->getKey());
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
    }

    public function test_negative_boundary_is_rejected_instead_of_inferred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->report(-1);
    }
}
