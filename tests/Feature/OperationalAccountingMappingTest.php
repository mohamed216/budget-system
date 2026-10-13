<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\OperationalAccounting\Actions\MapOperationalAccount;
use App\OperationalAccounting\Actions\MapOperationalCategory;
use App\OperationalAccounting\OperationalAccountingReadiness;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCompletedReconciliationBindings;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalAccountingMappingTest extends TestCase
{
    use CreatesCompletedReconciliationBindings;
    use RefreshFinancialDatabase;

    private User $owner;

    private Account $account;

    private Category $income;

    private Category $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = $this->owner->accounts()->create([
            'name' => 'Operational', 'type' => 'bank', 'balance' => '0.00', 'currency' => config('accounting.currency'),
        ]);
        $this->income = $this->owner->categories()->create(['name' => 'Income', 'type' => 'income']);
        $this->expense = $this->owner->categories()->create(['name' => 'Expense', 'type' => 'expense']);
    }

    private function chart(string $code, string $type, bool $active = true, ?User $owner = null, ?string $cashRole = 'non_cash'): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'user_id' => ($owner ?? $this->owner)->id, 'code' => $code, 'name' => $code,
            // Positive fixtures explicitly review a valid role; NULL is opt-in.
            'type' => $type, 'is_active' => $active, 'cash_role' => $cashRole,
        ]);
    }

    private function rejects(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Mapping action accepted invalid input.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function transaction(?Category $category = null): int
    {
        $category ??= $this->income;

        return DB::table('transactions')->insertGetId([
            'user_id' => $this->owner->id, 'account_id' => $this->account->id, 'category_id' => $category->id,
            'amount' => '1.00', 'type' => $category->type, 'date' => '2026-10-06',
        ]);
    }

    private function integration(string $strategy, int $highWater, string $state = 'ready', array $overrides = []): void
    {
        $row = array_replace([
            'user_id' => $this->owner->id, 'state' => $state,
            'historical_transaction_strategy' => $strategy, 'transaction_high_water_id' => $highWater,
            'reconciliation_reference' => 'audited-plan', 'reconciled_at' => '2026-10-10 10:00:00',
            'scope_reviewed_at' => '2026-10-10 10:00:00',
            'accounting_currency' => config('accounting.currency'),
            'activated_at' => $state === 'active' ? '2026-10-10 11:00:00' : null,
        ], $overrides);
        if (array_key_exists('reconciliation_reference', $overrides) && $overrides['reconciliation_reference'] === null) {
            $row['reconciled_at'] = null;
        } else {
            $row = $this->completedBindingFixture($row);
        }
        DB::table('operational_accounting_integrations')->insert($row);
    }

    private function scope(array $accountIds, array $categoryIds): void
    {
        // Test fixtures select IDs explicitly; neither mapping creation nor history
        // is allowed to populate participation scope in readiness.
        foreach ($accountIds as $accountId) {
            DB::table('operational_accounting_account_scope')->insert(['account_id' => $accountId, 'user_id' => $this->owner->id]);
        }
        foreach ($categoryIds as $categoryId) {
            DB::table('operational_accounting_category_scope')->insert(['category_id' => $categoryId, 'user_id' => $this->owner->id]);
        }
    }

    private function incomeMappings(): array
    {
        $asset = $this->chart('ASSET', 'asset');
        $revenue = $this->chart('REVENUE', 'revenue');
        (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income->id, $revenue, 'operating');

        return [$asset, $revenue];
    }

    private function linkIncome(int $transaction, int $asset, int $revenue): void
    {
        // Test-only posted fixture: readiness itself never posts or backfills.
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $this->owner->id, 'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
        ]);
        DB::table('journal_lines')->insert([
            ['user_id' => $this->owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $asset, 'line_number' => 1, 'debit' => '1.00', 'credit' => '0.00'],
            ['user_id' => $this->owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $revenue, 'line_number' => 2, 'debit' => '0.00', 'credit' => '1.00'],
        ]);
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
        DB::table('operational_transaction_journals')->insert([
            'transaction_id' => $transaction, 'journal_entry_id' => $journal, 'user_id' => $this->owner->id,
            'account_id' => $this->account->id, 'category_id' => $this->income->id,
        ]);
    }

    public function test_valid_account_mapping_is_owned_active_asset_only(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        $mapping = (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);

        $this->assertSame($asset, $mapping['chart_account_id']);
        $this->assertDatabaseHas('operational_account_mappings', [
            'account_id' => $this->account->id, 'user_id' => $this->owner->id, 'chart_account_id' => $asset,
        ]);
    }

    public function test_account_mapping_rejects_wrong_type_inactive_and_cross_owner_targets(): void
    {
        $this->rejects(fn () => (new MapOperationalAccount)->execute($this->owner, $this->account->id, $this->chart('REVENUE', 'revenue')), 'chart_account_id');
        $this->rejects(fn () => (new MapOperationalAccount)->execute($this->owner, $this->account->id, $this->chart('INACTIVE', 'asset', false)), 'chart_account_id');
        $other = User::factory()->create();
        $foreign = $this->chart('FOREIGN', 'asset', true, $other);
        $this->expectException(ModelNotFoundException::class);
        (new MapOperationalAccount)->execute($this->owner, $this->account->id, $foreign);
    }

    public function test_income_and_expense_category_mappings_require_matching_active_chart_types_and_review_metadata(): void
    {
        $revenue = $this->chart('REVENUE', 'revenue');
        $expense = $this->chart('EXPENSE', 'expense');
        (new MapOperationalCategory)->execute($this->owner, $this->income->id, $revenue, 'operating');
        (new MapOperationalCategory)->execute($this->owner, $this->expense->id, $expense, 'investing');

        $this->assertDatabaseHas('operational_category_mappings', [
            'category_id' => $this->income->id, 'user_id' => $this->owner->id,
            'chart_account_id' => $revenue, 'cash_flow_activity' => 'operating', 'reviewed_by' => $this->owner->id,
        ]);
        $this->assertNotNull(DB::table('operational_category_mappings')->where('category_id', $this->expense->id)->value('reviewed_at'));
        $this->rejects(fn () => (new MapOperationalCategory)->execute($this->owner, $this->income->id, $expense, 'operating'), 'chart_account_id');
        $this->rejects(fn () => (new MapOperationalCategory)->execute($this->owner, $this->expense->id, $this->chart('INACTIVE', 'expense', false), 'operating'), 'chart_account_id');
    }

    public function test_category_mapping_rejects_invalid_or_missing_activity_and_preserves_owner_isolation(): void
    {
        $revenue = $this->chart('REVENUE', 'revenue');
        $this->rejects(fn () => (new MapOperationalCategory)->execute($this->owner, $this->income->id, $revenue, ''), 'cash_flow_activity');
        $this->rejects(fn () => (new MapOperationalCategory)->execute($this->owner, $this->income->id, $revenue, 'inferred'), 'cash_flow_activity');
        $other = User::factory()->create();
        $foreign = $other->categories()->create(['name' => 'Foreign', 'type' => 'income']);
        $this->expectException(ModelNotFoundException::class);
        (new MapOperationalCategory)->execute($this->owner, $foreign->id, $revenue, 'operating');
    }

    public function test_mapping_freeze_remains_enforced_after_linked_posted_use(): void
    {
        $asset = $this->chart('ASSET', 'asset');
        $replacement = $this->chart('REPLACEMENT', 'asset');
        $revenue = $this->chart('REVENUE', 'revenue');
        (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income->id, $revenue, 'operating');
        $transaction = DB::table('transactions')->insertGetId([
            'user_id' => $this->owner->id, 'account_id' => $this->account->id, 'category_id' => $this->income->id,
            'amount' => '1.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $this->owner->id, 'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
        ]);
        DB::table('journal_lines')->insert([
            ['user_id' => $this->owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $asset, 'line_number' => 1, 'debit' => '1.00', 'credit' => '0.00'],
            ['user_id' => $this->owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $revenue, 'line_number' => 2, 'debit' => '0.00', 'credit' => '1.00'],
        ]);
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
        DB::table('operational_transaction_journals')->insert([
            'transaction_id' => $transaction, 'journal_entry_id' => $journal, 'user_id' => $this->owner->id,
            'account_id' => $this->account->id, 'category_id' => $this->income->id,
        ]);

        try {
            (new MapOperationalAccount)->execute($this->owner, $this->account->id, $replacement);
            $this->fail('Frozen mapping was changed.');
        } catch (QueryException $exception) {
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
        foreach ([$this->chart('OTHER-REVENUE', 'revenue'), $revenue] as $target) {
            try {
                (new MapOperationalCategory)->execute($this->owner, $this->income->id, $target, $target === $revenue ? 'investing' : 'operating');
                $this->fail('Frozen category target/activity was changed.');
            } catch (QueryException $exception) {
                $this->assertSame(1644, $exception->errorInfo[1]);
            }
        }
        $this->assertDatabaseHas('operational_category_mappings', [
            'category_id' => $this->income->id, 'chart_account_id' => $revenue, 'cash_flow_activity' => 'operating',
        ]);
    }

    public function test_readiness_reports_missing_invalid_currency_and_unreconciled_conditions_without_cross_owner_data(): void
    {
        $missingAccount = $this->owner->accounts()->create(['name' => 'Missing', 'type' => 'cash', 'balance' => '1.00', 'currency' => 'EUR']);
        $this->scope([$this->account->id, $missingAccount->id], [$this->income->id, $this->expense->id]);
        $badAsset = $this->chart('BAD-ASSET', 'liability');
        $badRevenue = $this->chart('BAD-REVENUE', 'expense');
        DB::table('operational_account_mappings')->insert([
            'account_id' => $this->account->id, 'user_id' => $this->owner->id, 'chart_account_id' => $badAsset,
        ]);
        DB::table('operational_category_mappings')->insert([
            'category_id' => $this->income->id, 'user_id' => $this->owner->id, 'chart_account_id' => $badRevenue,
        ]);
        $transaction = DB::table('transactions')->insertGetId([
            'user_id' => $this->owner->id, 'account_id' => $this->account->id, 'category_id' => $this->income->id,
            'amount' => '1.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);
        DB::table('operational_accounting_integrations')->insert([
            'user_id' => $this->owner->id, 'state' => 'reconciling', 'accounting_currency' => 'USD',
            'historical_transaction_strategy' => 'historical_backfill',
            'transaction_high_water_id' => $transaction,
        ]);
        // This category was selected explicitly above. Its above-boundary transaction
        // must not be reported as unresolved historical history.
        $this->transaction($this->expense);
        $other = User::factory()->create();
        $foreignAccount = $other->accounts()->create(['name' => 'Foreign', 'type' => 'cash', 'balance' => '0.00', 'currency' => 'USD']);

        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertContains($missingAccount->id, $readiness['issues']['missing_account_mapping_ids']);
        $this->assertContains($this->account->id, $readiness['issues']['invalid_account_mapping_ids']);
        $this->assertContains($this->expense->id, $readiness['issues']['missing_category_mapping_ids']);
        $this->assertContains($this->income->id, $readiness['issues']['invalid_category_mapping_ids']);
        $this->assertContains($this->income->id, $readiness['issues']['unreviewed_category_mapping_ids']);
        $this->assertContains($missingAccount->id, $readiness['issues']['currency_mismatch_account_ids']);
        $this->assertSame([$this->owner->id], $readiness['issues']['integration_currency_mismatch']);
        $this->assertSame([$transaction], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertNotContains($foreignAccount->id, $readiness['issues']['currency_mismatch_account_ids']);
    }

    public function test_category_mapping_rejects_foreign_chart_target(): void
    {
        $other = User::factory()->create();
        $foreignRevenue = $this->chart('FOREIGN-REVENUE', 'revenue', true, $other);
        $this->expectException(ModelNotFoundException::class);
        (new MapOperationalCategory)->execute($this->owner, $this->income->id, $foreignRevenue, 'operating');
    }

    public function test_historical_backfill_requires_links_only_through_high_water(): void
    {
        [$asset, $revenue] = $this->incomeMappings();
        $this->scope([$this->account->id], [$this->income->id]);
        $historical = $this->transaction();
        $aboveBoundary = $this->transaction();
        $this->integration('historical_backfill', $historical);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$historical], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertNotContains($aboveBoundary, $readiness['issues']['unreconciled_transaction_ids']);
        foreach (['ready', 'active'] as $state) {
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update([
                'state' => $state, 'accounting_currency' => config('accounting.currency'),
                'activated_at' => $state === 'active' ? '2026-10-10 11:00:00' : null,
            ]);
            $this->assertFalse((new OperationalAccountingReadiness)->for($this->owner)['ready'], $state);
        }

        $this->linkIncome($historical, $asset, $revenue);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertSame([], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertTrue($readiness['ready']);
    }

    public function test_balance_only_legacy_rows_need_no_links_or_category_mappings_and_readiness_is_read_only(): void
    {
        $historical = $this->transaction();
        $this->integration('balance_only', $historical);
        // The reviewed scope is explicitly empty. Balance-only history does not
        // require participation rows, mappings, or historical links.
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertSame([], $readiness['issues']['missing_account_mapping_ids']);
        $this->assertSame([], $readiness['issues']['missing_category_mapping_ids']);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^select\b/i', $sql);
        }
        $this->assertDatabaseCount('operational_transaction_journals', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseHas('accounts', ['id' => $this->account->id, 'balance' => '0.00']);
    }

    public function test_balance_only_requires_reconciliation_evidence_and_a_boundary(): void
    {
        $historical = $this->transaction();
        $this->integration('balance_only', $historical, 'reconciling');
        foreach ([['reconciliation_reference' => null, 'reconciled_at' => null],
            ['transaction_high_water_id' => null, 'reconciliation_reference' => null, 'reconciled_at' => null]] as $missing) {
            $original = (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update($missing);
            $readiness = (new OperationalAccountingReadiness)->for($this->owner);
            $this->assertFalse($readiness['ready']);
            $this->assertSame([], $readiness['issues']['unreconciled_transaction_ids']);
            $issue = array_key_exists('transaction_high_water_id', $missing) ? 'missing_transaction_high_water' : 'missing_reconciliation_evidence';
            $this->assertSame([$this->owner->id], $readiness['issues'][$issue]);
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update($original);
        }
    }

    public function test_selected_unmapped_records_block_and_unselected_records_do_not_become_participants(): void
    {
        $historical = $this->transaction();
        $this->account->update(['balance' => '10.00']);
        $this->integration('balance_only', $historical);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['issues']['missing_account_mapping_ids']);
        $this->assertSame([], $readiness['issues']['missing_category_mapping_ids']);

        $aboveBoundary = $this->transaction($this->expense);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['issues']['missing_category_mapping_ids']);
        $this->assertNotContains($aboveBoundary, $readiness['issues']['unreconciled_transaction_ids']);
        // Selection also requires mapping at zero balance; balance never decides scope.
        $this->account->update(['balance' => '0.00']);
        $this->scope([$this->account->id], []);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->account->id], $readiness['issues']['missing_account_mapping_ids']);
        $integration = DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->firstOrFail();
        $this->assertSame('ready', $integration->state);
        $this->assertNotNull($integration->reconciliation_reference);
        $this->assertNotNull($integration->reconciled_at);
        $review = (array) DB::table('operational_reconciliation_reviews')->where('user_id', $this->owner->id)
            ->where('reference', $integration->reconciliation_reference)->firstOrFail();
        $this->assertSame($this->owner->id, $review['reviewed_by']);
        $this->assertSame($integration->reconciled_at, $review['completed_at']);

        $asset = $this->chart('ASSET', 'asset');
        $mapping = (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);
        $this->assertSame($asset, $mapping['chart_account_id']);
        $this->assertDatabaseHas('operational_account_mappings', [
            'user_id' => $this->owner->id, 'account_id' => $this->account->id, 'chart_account_id' => $asset,
        ]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertSame([], $readiness['issues']['missing_account_mapping_ids']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(['missing_reconciliation_evidence' => [$this->owner->id]], array_filter($readiness['issues']));
        $unbound = (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->firstOrFail();
        $this->assertSame('reconciling', $unbound['state']);
        $this->assertNull($unbound['reconciliation_reference']);
        $this->assertNull($unbound['reconciled_at']);
        $this->assertSame($review, (array) DB::table('operational_reconciliation_reviews')->where('id', $review['id'])->firstOrFail());

        $this->scope([], [$this->expense->id]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->expense->id], $readiness['issues']['missing_category_mapping_ids']);
        $this->assertSame([$this->owner->id], $readiness['issues']['missing_reconciliation_evidence']);
        $expense = $this->chart('EXPENSE', 'expense');
        $mapping = (new MapOperationalCategory)->execute($this->owner, $this->expense->id, $expense, 'operating');
        $this->assertSame($expense, $mapping['chart_account_id']);
        $this->assertSame('operating', $mapping['cash_flow_activity']);
        $this->assertSame($this->owner->id, $mapping['reviewed_by']);
        $this->assertNotNull($mapping['reviewed_at']);
        $this->assertDatabaseHas('operational_category_mappings', [
            'user_id' => $this->owner->id, 'category_id' => $this->expense->id,
            'chart_account_id' => $expense, 'cash_flow_activity' => 'operating', 'reviewed_by' => $this->owner->id,
        ]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertSame([], $readiness['issues']['missing_category_mapping_ids']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(['missing_reconciliation_evidence' => [$this->owner->id]], array_filter($readiness['issues']));
        $this->assertSame($unbound, (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->firstOrFail());

        // A mapping outside scope, even unreviewed, never selects its category.
        $revenue = $this->chart('REVENUE', 'revenue');
        DB::table('operational_category_mappings')->insert([
            'user_id' => $this->owner->id, 'category_id' => $this->income->id,
            'chart_account_id' => $revenue,
        ]);
        $this->assertDatabaseHas('operational_category_mappings', [
            'user_id' => $this->owner->id, 'category_id' => $this->income->id, 'chart_account_id' => $revenue,
        ]);
        $this->assertDatabaseMissing('operational_accounting_category_scope', [
            'user_id' => $this->owner->id, 'category_id' => $this->income->id,
        ]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertSame([], $readiness['issues']['unreviewed_category_mapping_ids']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(['missing_reconciliation_evidence' => [$this->owner->id]], array_filter($readiness['issues']));
        $this->assertSame($unbound, (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->firstOrFail());
        $this->scope([], [$this->income->id]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertSame([$this->income->id], $readiness['issues']['unreviewed_category_mapping_ids']);
        $this->assertSame([$this->owner->id], $readiness['issues']['missing_reconciliation_evidence']);
        $this->assertFalse($readiness['ready']);
    }

    public function test_state_distinctions_and_missing_or_inconsistent_metadata(): void
    {
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->owner->id], $readiness['issues']['missing_integration_metadata']);
        $this->integration('balance_only', 0, 'legacy');
        $this->assertSame(['missing_reconciliation_evidence' => [$this->owner->id]], array_filter((new OperationalAccountingReadiness)->for($this->owner)['issues']));
        $this->assertFalse((new OperationalAccountingReadiness)->for($this->owner)['ready']);
        foreach (['reconciling', 'ready', 'active'] as $state) {
            $current = (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update($this->completedBindingFixture(array_replace($current, [
                'state' => $state, 'accounting_currency' => config('accounting.currency'),
                'activated_at' => $state === 'active' ? '2026-10-10 11:00:00' : null,
            ])));
            $this->assertSame($state !== 'reconciling', (new OperationalAccountingReadiness)->for($this->owner)['ready'], $state);
        }
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update(['activated_at' => '2026-10-10 09:00:00']);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->owner->id], $readiness['issues']['inconsistent_integration_metadata']);
        $current = (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update($this->completedBindingFixture(array_replace($current, [
            'activated_at' => '2026-10-10 11:00:00', 'scope_reviewed_at' => '2026-10-10 12:00:00',
        ])));
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->owner->id], $readiness['issues']['inconsistent_integration_metadata']);
        $current = (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update($this->completedBindingFixture(array_replace($current, [
            'activated_at' => '2026-10-10 10:00:00', 'scope_reviewed_at' => '2026-10-10 10:00:00',
        ])));
        $this->assertTrue((new OperationalAccountingReadiness)->for($this->owner)['ready']);
        // Separate draft fixture: never demote an active owner into legacy posting.
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->delete();
        $this->integration('balance_only', 0, 'reconciling', ['historical_transaction_strategy' => null]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->owner->id], $readiness['issues']['invalid_historical_transaction_strategy']);
    }

    public function test_ready_rejects_blank_evidence_and_active_currency_conflicts(): void
    {
        try {
            DB::table('operational_accounting_integrations')->insert([
                'user_id' => $this->owner->id, 'state' => 'ready', 'historical_transaction_strategy' => 'balance_only',
                'transaction_high_water_id' => 0, 'scope_reviewed_at' => '2026-10-10 10:00:00',
                'reconciliation_reference' => '  ', 'reconciled_at' => '2026-10-10 10:00:00',
            ]);
            $this->fail('Blank completion evidence was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
        $this->integration('balance_only', 0, 'active', ['accounting_currency' => 'USD']);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->owner->id], $readiness['issues']['integration_currency_mismatch']);
    }

    public function test_unexpected_category_types_are_reported_without_mapping_inference_or_foreign_data(): void
    {
        $unexpected = $this->owner->categories()->create(['name' => 'Unexpected', 'type' => 'transfer']);
        $other = User::factory()->create();
        $foreign = $other->categories()->create(['name' => 'Foreign unexpected', 'type' => 'transfer']);
        $other->accounts()->create(['name' => 'Foreign balance', 'type' => 'cash', 'balance' => '1.00', 'currency' => 'USD']);
        $this->integration('balance_only', 0);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], $readiness['issues']['unexpected_category_type_ids']);
        $this->scope([], [$unexpected->id]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$unexpected->id], $readiness['issues']['unexpected_category_type_ids']);
        $this->assertNotContains($foreign->id, $readiness['issues']['unexpected_category_type_ids']);
        $this->assertSame([$unexpected->id], $readiness['issues']['missing_category_mapping_ids']);
        $this->assertSame([], $readiness['issues']['missing_account_mapping_ids']);
        $this->assertSame([], $readiness['issues']['currency_mismatch_account_ids']);
        $this->rejects(fn () => (new MapOperationalCategory)->execute($this->owner, $unexpected->id, $this->chart('REVENUE', 'revenue'), 'operating'), 'chart_account_id');
    }

    public function test_scoped_asset_requires_a_reviewed_role_and_accepts_all_valid_asset_roles(): void
    {
        $asset = $this->chart('UNREVIEWED-ASSET', 'asset', cashRole: null);
        (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);
        $this->scope([$this->account->id], []);
        $this->integration('balance_only', 0);

        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(['unreviewed_chart_cash_role_ids' => [$asset]], array_filter($readiness['issues']));
        $this->assertNull(DB::table('chart_of_accounts')->where('id', $asset)->value('cash_role'));

        foreach (['cash', 'cash_equivalent', 'non_cash'] as $role) {
            DB::table('chart_of_accounts')->where('id', $asset)->update(['cash_role' => $role]);
            $readiness = (new OperationalAccountingReadiness)->for($this->owner);
            $this->assertTrue($readiness['ready'], $role);
            $this->assertSame([], array_filter($readiness['issues']));
        }
    }

    public function test_scoped_income_and_expense_require_reviewed_roles_with_unique_chart_diagnostics(): void
    {
        foreach ([[$this->income, 'revenue'], [$this->expense, 'expense']] as [$category, $type]) {
            $target = $this->chart('UNREVIEWED-'.$type, $type, cashRole: null);
            $shared = $this->owner->categories()->create(['name' => 'Shared '.$type, 'type' => $category->type]);
            foreach ([$category, $shared] as $selected) {
                (new MapOperationalCategory)->execute($this->owner, $selected->id, $target, 'operating');
            }
            $this->scope([], [$category->id, $shared->id]);
        }
        $this->integration('balance_only', 0);
        $targets = DB::table('operational_category_mappings')->where('user_id', $this->owner->id)
            ->distinct()->orderBy('chart_account_id')->pluck('chart_account_id')->all();

        foreach ($targets as $target) {
            $readiness = (new OperationalAccountingReadiness)->for($this->owner);
            $remaining = DB::table('chart_of_accounts')->whereIn('id', $targets)->whereNull('cash_role')->orderBy('id')->pluck('id')->all();
            $this->assertFalse($readiness['ready']);
            $this->assertSame(['unreviewed_chart_cash_role_ids' => $remaining], array_filter($readiness['issues']));
            DB::table('chart_of_accounts')->where('id', $target)->update(['cash_role' => 'non_cash']);
        }
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], array_filter($readiness['issues']));
    }

    public function test_reviewing_a_cash_role_does_not_remove_other_readiness_blockers(): void
    {
        $asset = $this->chart('UNREVIEWED-ASSET', 'asset', cashRole: null);
        (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);
        $this->scope([$this->account->id], []);
        $this->account->update(['currency' => 'USD']);
        $this->integration('balance_only', 0, 'reconciling', ['reconciliation_reference' => null]);
        $before = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($before['ready']);
        $this->assertSame([$asset], $before['issues']['unreviewed_chart_cash_role_ids']);
        DB::table('chart_of_accounts')->where('id', $asset)->update(['cash_role' => 'non_cash']);
        $after = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($after['ready']);
        $this->assertSame([], $after['issues']['unreviewed_chart_cash_role_ids']);
        $before['issues']['unreviewed_chart_cash_role_ids'] = [];
        $this->assertSame($before, $after);
    }

    public function test_unreviewed_roles_outside_scope_or_under_another_owner_do_not_block_readiness(): void
    {
        $asset = $this->chart('UNSELECTED-ASSET', 'asset', cashRole: null);
        $revenue = $this->chart('UNSELECTED-REVENUE', 'revenue', cashRole: null);
        (new MapOperationalAccount)->execute($this->owner, $this->account->id, $asset);
        (new MapOperationalCategory)->execute($this->owner, $this->income->id, $revenue, 'operating');

        $other = User::factory()->create();
        $account = $other->accounts()->create(['name' => 'Foreign', 'type' => 'bank', 'balance' => '0.00', 'currency' => config('accounting.currency')]);
        $category = $other->categories()->create(['name' => 'Foreign', 'type' => 'expense']);
        $foreignAsset = $this->chart('FOREIGN-ASSET', 'asset', owner: $other, cashRole: null);
        $foreignExpense = $this->chart('FOREIGN-EXPENSE', 'expense', owner: $other, cashRole: null);
        (new MapOperationalAccount)->execute($other, $account->id, $foreignAsset);
        (new MapOperationalCategory)->execute($other, $category->id, $foreignExpense, 'operating');
        DB::table('operational_accounting_account_scope')->insert(['account_id' => $account->id, 'user_id' => $other->id]);
        DB::table('operational_accounting_category_scope')->insert(['category_id' => $category->id, 'user_id' => $other->id]);
        $this->integration('balance_only', 0);

        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], array_filter($readiness['issues']));
        $this->assertSame(4, DB::table('chart_of_accounts')->whereIn('id', [$asset, $revenue, $foreignAsset, $foreignExpense])->whereNull('cash_role')->count());
        $foreignReadiness = (new OperationalAccountingReadiness)->for($other);
        $this->assertFalse($foreignReadiness['ready']);
        $this->assertSame([$foreignAsset, $foreignExpense], $foreignReadiness['issues']['unreviewed_chart_cash_role_ids']);
    }

    public function test_readiness_detects_inactive_chart_targets(): void
    {
        [$asset, $revenue] = $this->incomeMappings();
        $this->scope([$this->account->id], [$this->income->id]);
        DB::table('chart_of_accounts')->whereIn('id', [$asset, $revenue])->update(['is_active' => false]);
        $this->integration('balance_only', 0);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->account->id], $readiness['issues']['invalid_account_mapping_ids']);
        $this->assertSame([$this->income->id], $readiness['issues']['invalid_category_mapping_ids']);
    }

    public function test_backfill_inventory_is_owner_scoped_and_does_not_infer_a_missing_boundary(): void
    {
        $other = User::factory()->create();
        $foreignAccount = $other->accounts()->create(['name' => 'Foreign', 'type' => 'cash', 'balance' => '1.00', 'currency' => 'USD']);
        $foreignCategory = $other->categories()->create(['name' => 'Foreign income', 'type' => 'income']);
        $foreignTransaction = DB::table('transactions')->insertGetId([
            'user_id' => $other->id, 'account_id' => $foreignAccount->id, 'category_id' => $foreignCategory->id,
            'amount' => '1.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);
        $this->incomeMappings();
        $this->scope([$this->account->id], [$this->income->id]);
        $historical = $this->transaction();
        $this->integration('historical_backfill', $historical);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertSame([$historical], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertNotContains($foreignTransaction, $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertSame([], $readiness['issues']['missing_account_mapping_ids']);
        $this->assertSame([], $readiness['issues']['missing_category_mapping_ids']);
        // Missing readiness metadata is valid during reconciliation, never in ready.
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update([
            'state' => 'reconciling', 'transaction_high_water_id' => null,
            'reconciliation_reference' => null, 'reconciled_at' => null,
        ]);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$this->owner->id], $readiness['issues']['missing_transaction_high_water']);
        $this->assertSame([], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertSame(['missing_transaction_high_water' => [$this->owner->id], 'missing_reconciliation_evidence' => [$this->owner->id]], array_filter($readiness['issues']));
        $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->value('transaction_high_water_id'));
    }

    public function test_reviewed_empty_scope_is_distinct_from_unreviewed_scope(): void
    {
        $this->integration('balance_only', 0, 'reconciling', ['scope_reviewed_at' => null]);
        foreach (['legacy', 'reconciling'] as $state) {
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update(['state' => $state]);
            $readiness = (new OperationalAccountingReadiness)->for($this->owner);
            $this->assertFalse($readiness['ready']);
            $this->assertSame([$this->owner->id], $readiness['issues']['unreviewed_scope']);
        }
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update(['scope_reviewed_at' => '2026-10-10 10:00:00']);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([], $readiness['issues']['unreviewed_scope']);
        $current = (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update($this->completedBindingFixture(array_replace($current, ['state' => 'ready'])));
        $this->assertTrue((new OperationalAccountingReadiness)->for($this->owner)['ready']);
        $this->assertDatabaseCount('operational_accounting_account_scope', 0);
        $this->assertDatabaseCount('operational_accounting_category_scope', 0);
    }

    public function test_existing_invalid_mappings_outside_scope_do_not_select_records(): void
    {
        DB::table('operational_account_mappings')->insert([
            'account_id' => $this->account->id, 'user_id' => $this->owner->id,
            'chart_account_id' => $this->chart('WRONG-ASSET', 'liability'),
        ]);
        DB::table('operational_category_mappings')->insert([
            'category_id' => $this->income->id, 'user_id' => $this->owner->id,
            'chart_account_id' => $this->chart('WRONG-REVENUE', 'expense'),
        ]);
        $this->account->update(['balance' => '100.00', 'currency' => 'USD']);
        $this->integration('balance_only', 0);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], array_filter($readiness['issues']));
        $this->assertDatabaseCount('operational_accounting_account_scope', 0);
        $this->assertDatabaseCount('operational_accounting_category_scope', 0);
    }

    public function test_backfill_cannot_exempt_linked_transactions_by_omitting_either_scope(): void
    {
        [$asset, $revenue] = $this->incomeMappings();
        $historical = $this->transaction();
        // Above-boundary usage of an unselected category adds no participants.
        $this->transaction($this->expense);
        $this->linkIncome($historical, $asset, $revenue);
        $this->integration('historical_backfill', $historical);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$historical], $readiness['issues']['historical_transactions_missing_account_scope_ids']);
        $this->assertSame([$historical], $readiness['issues']['historical_transactions_missing_category_scope_ids']);
        $this->assertSame([], $readiness['issues']['unreconciled_transaction_ids']);
        $this->assertSame([], $readiness['issues']['missing_account_mapping_ids']);
        $this->assertSame([], $readiness['issues']['missing_category_mapping_ids']);
        $this->scope([$this->account->id], []);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([], $readiness['issues']['historical_transactions_missing_account_scope_ids']);
        $this->assertSame([$historical], $readiness['issues']['historical_transactions_missing_category_scope_ids']);
        $this->scope([], [$this->income->id]);
        $this->assertTrue((new OperationalAccountingReadiness)->for($this->owner)['ready']);
        DB::table('operational_accounting_account_scope')->where('account_id', $this->account->id)->delete();
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertFalse($readiness['ready']);
        $this->assertSame([$historical], $readiness['issues']['historical_transactions_missing_account_scope_ids']);
        $this->assertSame([], $readiness['issues']['historical_transactions_missing_category_scope_ids']);
    }

    public function test_foreign_owner_scope_and_mappings_do_not_affect_readiness(): void
    {
        $other = User::factory()->create();
        $account = $other->accounts()->create(['name' => 'Foreign', 'type' => 'cash', 'balance' => '100.00', 'currency' => 'USD']);
        $category = $other->categories()->create(['name' => 'Foreign', 'type' => 'income']);
        DB::table('operational_accounting_account_scope')->insert(['account_id' => $account->id, 'user_id' => $other->id]);
        DB::table('operational_accounting_category_scope')->insert(['category_id' => $category->id, 'user_id' => $other->id]);
        DB::table('operational_account_mappings')->insert([
            'account_id' => $account->id, 'user_id' => $other->id, 'chart_account_id' => $this->chart('FOREIGN-WRONG', 'liability', true, $other),
        ]);
        // The foreign category has no mapping; neither foreign problem may leak.
        $this->integration('balance_only', 0);
        $readiness = (new OperationalAccountingReadiness)->for($this->owner);
        $this->assertTrue($readiness['ready']);
        $this->assertSame([], array_filter($readiness['issues']));
    }
}
