<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Actions\PostTransaction;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesCompletedReconciliationBindings;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalTransactionGateTest extends TestCase
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
        $this->account = $this->owner->accounts()->create(['name' => 'Operational', 'type' => 'bank',
            'balance' => '100.00', 'currency' => config('accounting.currency')]);
        $this->income = $this->owner->categories()->create(['name' => 'Income', 'type' => 'income']);
        $this->expense = $this->owner->categories()->create(['name' => 'Expense', 'type' => 'expense']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['account_id' => $this->account->id, 'category_id' => $this->income->id,
            'amount' => '0.10', 'type' => 'income', 'date' => '2026-10-06', 'description' => 'Legacy create'], $overrides);
    }

    private function gate(string $state, string $strategy = 'balance_only', ?User $owner = null): void
    {
        DB::table('operational_accounting_integrations')->insert($this->completedBindingFixture([
            'user_id' => ($owner ?? $this->owner)->id, 'state' => $state,
            'historical_transaction_strategy' => $strategy, 'accounting_currency' => config('accounting.currency'),
            'transaction_high_water_id' => 0, 'reconciliation_reference' => 'reviewed-evidence',
            'reconciled_at' => '2026-10-10 10:00:00', 'scope_reviewed_at' => '2026-10-10 10:00:00',
            // Deliberately populated even for non-active fixtures: this slice must
            // leave this field untouched, not infer or repair activation history.
            'activated_at' => '2026-10-10 11:00:00',
        ]));
    }

    private function integration(): array
    {
        return (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
    }

    private function assertNoAccountingWrites(): void
    {
        foreach (['journal_entries', 'journal_lines', 'operational_transaction_journals', 'opening_balance_batches'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_no_gate_keeps_exact_legacy_income_expense_behavior_and_response(): void
    {
        $transaction = (new PostTransaction)->handle($this->owner, $this->data());
        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame($this->owner->id, $transaction->user_id);
        $this->assertSame('0.10', $transaction->amount);
        $this->assertSame('100.10', $this->account->fresh()->balance);
        $this->actingAs($this->owner)->post('/transactions', $this->data(['amount' => '0.20']))
            ->assertRedirect('/transactions')->assertSessionHas('success', 'Transaction added');
        (new PostTransaction)->handle($this->owner, $this->data(['type' => 'expense', 'category_id' => $this->expense->id, 'amount' => '101.00']));
        $this->assertSame('-0.70', $this->account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 3);
        $this->assertDatabaseCount('operational_accounting_integrations', 0);
        $this->assertNoAccountingWrites();
    }

    public static function nonActiveStates(): array
    {
        return [
            'legacy backfill' => ['legacy', 'historical_backfill'],
            'legacy balance only' => ['legacy', 'balance_only'],
            'reconciling backfill' => ['reconciling', 'historical_backfill'],
            'reconciling balance only' => ['reconciling', 'balance_only'],
            'ready backfill' => ['ready', 'historical_backfill'],
            'ready balance only' => ['ready', 'balance_only'],
        ];
    }

    #[DataProvider('nonActiveStates')]
    public function test_non_active_creates_clear_completion_without_changing_the_reviewed_plan(string $state, string $strategy): void
    {
        $this->gate($state, $strategy);
        $asset = DB::table('chart_of_accounts')->insertGetId(['user_id' => $this->owner->id,
            'code' => 'ASSET', 'name' => 'Asset', 'type' => 'asset', 'is_active' => true, 'cash_role' => 'non_cash']);
        $revenue = DB::table('chart_of_accounts')->insertGetId(['user_id' => $this->owner->id,
            'code' => 'REVENUE', 'name' => 'Revenue', 'type' => 'revenue', 'is_active' => true, 'cash_role' => 'non_cash']);
        DB::table('operational_accounting_account_scope')->insert(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
        DB::table('operational_accounting_category_scope')->insert(['category_id' => $this->income->id, 'user_id' => $this->owner->id]);
        DB::table('operational_account_mappings')->insert(['account_id' => $this->account->id,
            'user_id' => $this->owner->id, 'chart_account_id' => $asset]);
        DB::table('operational_category_mappings')->insert(['category_id' => $this->income->id,
            'user_id' => $this->owner->id, 'chart_account_id' => $revenue, 'cash_flow_activity' => 'investing',
            'reviewed_by' => $this->owner->id, 'reviewed_at' => '2026-10-10 10:00:00']);
        $protected = [];
        foreach (['chart_of_accounts', 'operational_accounting_account_scope', 'operational_accounting_category_scope',
            'operational_account_mappings', 'operational_category_mappings'] as $table) {
            $protected[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        $before = $this->integration();
        // Posting an unselected expense must still invalidate the owner snapshot;
        // missing expense mapping must not block this pre-activation legacy path.
        $transaction = (new PostTransaction)->handle($this->owner, $this->data(['type' => 'expense',
            'category_id' => $this->expense->id, 'amount' => '0.01']));
        $after = $this->integration();
        $this->assertSame($state === 'ready' ? 'reconciling' : $state, $after['state']);
        $this->assertNull($after['reconciliation_reference']);
        $this->assertNull($after['reconciled_at']);
        foreach (['user_id', 'historical_transaction_strategy', 'accounting_currency', 'transaction_high_water_id',
            'scope_reviewed_at', 'activated_at', 'created_at'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
        $this->assertGreaterThan($after['transaction_high_water_id'], $transaction->id);
        $this->assertSame('99.99', $this->account->fresh()->balance);
        foreach ($protected as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->map(fn ($row) => (array) $row)->all(), $table);
        }
        $this->assertNoAccountingWrites();
    }

    public function test_incomplete_reconciling_gate_allows_creates_without_populating_any_metadata(): void
    {
        DB::table('operational_accounting_integrations')->insert(['user_id' => $this->owner->id, 'state' => 'reconciling']);
        $before = $this->integration();
        (new PostTransaction)->handle($this->owner, $this->data());
        $this->assertSame($before, $this->integration());
        $this->assertSame('100.10', $this->account->fresh()->balance);
        $this->assertNoAccountingWrites();
    }

    public function test_active_gate_rejects_before_financial_writes_and_preserves_all_metadata(): void
    {
        $this->gate('active');
        $before = $this->integration();
        try {
            (new PostTransaction)->handle($this->owner, $this->data());
            $this->fail('Active owner bypassed integrated posting.');
        } catch (AccountingConflict $exception) {
            $this->assertSame('Legacy transaction posting is unavailable for this integration state.', $exception->getMessage());
        }
        $this->assertSame($before, $this->integration());
        $this->assertSame('100.00', $this->account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertNoAccountingWrites();
    }

    public function test_unknown_state_fails_closed_without_weakening_database_checks(): void
    {
        // An invalid state cannot be inserted under the real CHECK. Simulate the
        // gate read only, leaving schema protections and financial writes intact.
        $manager = DB::getFacadeRoot();
        $proxy = Mockery::mock($manager)->makePartial();
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('where')->once()->with('user_id', $this->owner->id)->andReturnSelf();
        $builder->shouldReceive('lockForUpdate')->once()->andReturnSelf();
        $builder->shouldReceive('first')->once()->andReturn((object) ['state' => 'unknown']);
        $proxy->shouldReceive('table')->once()->with('operational_accounting_integrations')->andReturn($builder);
        DB::swap($proxy);
        try {
            try {
                (new PostTransaction)->handle($this->owner, $this->data());
                $this->fail('Unknown state allowed legacy posting.');
            } catch (AccountingConflict $exception) {
                $this->assertSame('Legacy transaction posting is unavailable for this integration state.', $exception->getMessage());
            }
        } finally {
            DB::swap($manager);
        }
        $this->assertSame('100.00', $this->account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('operational_accounting_integrations', 0);
    }

    public function test_failure_after_evidence_update_rolls_back_demoted_gate_transaction_and_balance(): void
    {
        $this->gate('ready');
        $before = $this->integration();
        $level = DB::transactionLevel();
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $observed = null;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$observed) {
            if (str_starts_with(strtolower($event->sql), 'update `operational_accounting_integrations`')) {
                $observed = $this->integration();
                throw new RuntimeException('Injected failure after evidence invalidation.');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            try {
                (new PostTransaction)->handle($this->owner, $this->data());
                $this->fail('Injected failure was not propagated.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected failure after evidence invalidation.', $exception->getMessage());
            }
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
        $this->assertNotNull($observed);
        $this->assertSame('reconciling', $observed['state']);
        $this->assertNull($observed['reconciliation_reference']);
        $this->assertNull($observed['reconciled_at']);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->integration());
        $this->assertSame('100.00', $this->account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertNoAccountingWrites();
    }

    public function test_balance_overflow_preserves_ready_evidence_and_rolls_back_the_insert(): void
    {
        $this->gate('ready');
        $this->account->update(['balance' => '9999999999999.99']);
        $before = $this->integration();
        try {
            (new PostTransaction)->handle($this->owner, $this->data(['amount' => '0.01']));
            $this->fail('Overflow was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame(1264, $exception->errorInfo[1]);
        }
        $this->assertSame($before, $this->integration());
        $this->assertSame('9999999999999.99', $this->account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_lock_order_is_owner_account_category_then_existing_integration_gate(): void
    {
        $this->gate('ready');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new PostTransaction)->handle($this->owner, $this->data());
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $locks = collect($queries)->filter(fn ($query) => str_contains(strtolower($query['query']), 'for update'))->values();
        $this->assertCount(4, $locks);
        foreach (['users', 'accounts', 'categories', 'operational_accounting_integrations'] as $index => $table) {
            $this->assertStringContainsString('`'.$table.'`', $locks[$index]['query']);
            $this->assertContains($this->owner->id, $locks[$index]['bindings']);
        }
        $this->assertStringContainsString('`users`', $queries[0]['query']);
        $updates = collect($queries)->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'update `operational_accounting_integrations`'))->values();
        $this->assertCount(1, $updates);
        foreach (['state', 'reconciliation_reference', 'reconciled_at'] as $column) {
            $this->assertStringContainsString('`'.$column.'`', $updates[0]['query']);
        }
    }

    public function test_invalid_owner_and_foreign_or_unassigned_references_cannot_be_posted(): void
    {
        $other = User::factory()->create();
        $foreignAccount = $other->accounts()->create(['name' => 'Foreign', 'type' => 'bank', 'balance' => '10.00']);
        $foreignCategory = $other->categories()->create(['name' => 'Foreign', 'type' => 'income']);
        $this->gate('active', owner: $other);
        $unassigned = DB::table('accounts')->insertGetId(['user_id' => null, 'name' => 'Unassigned', 'type' => 'bank', 'balance' => '20.00']);
        $unassignedCategory = DB::table('categories')->insertGetId(['user_id' => null, 'name' => 'Unassigned', 'type' => 'income']);
        foreach ([['account_id' => $foreignAccount->id], ['category_id' => $foreignCategory->id],
            ['account_id' => $unassigned], ['category_id' => $unassignedCategory]] as $override) {
            try {
                (new PostTransaction)->handle($this->owner, $this->data($override));
                $this->fail('Foreign or unassigned reference was accepted.');
            } catch (ModelNotFoundException $exception) {
                $this->assertContains($exception->getModel(), [Account::class, Category::class]);
            }
        }
        $unsaved = new User;
        $unsavedWithKey = new User;
        $unsavedWithKey->setAttribute('id', $this->owner->id);
        $missingKey = clone $this->owner;
        $missingKey->setAttribute('id', null);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ([$unsaved, $unsavedWithKey, $missingKey] as $actor) {
                try {
                    (new PostTransaction)->handle($actor, $this->data(['account_id' => $unassigned, 'category_id' => $unassignedCategory]));
                    $this->fail('Invalid owner was accepted.');
                } catch (ModelNotFoundException $exception) {
                    $this->assertSame(User::class, $exception->getModel());
                }
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame('100.00', $this->account->fresh()->balance);
        $this->assertSame('10.00', $foreignAccount->fresh()->balance);
        $this->assertSame('20.00', DB::table('accounts')->where('id', $unassigned)->value('balance'));
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('operational_accounting_integrations', 1);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $other->id,
            'state' => 'active', 'reconciliation_reference' => $this->bindingFixtureReferences[$other->id]]);
        // Another owner's active gate must not block this owner's legitimate create.
        (new PostTransaction)->handle($this->owner, $this->data());
        $this->assertSame('100.10', $this->account->fresh()->balance);
        $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $this->owner->id]);
    }

    public function test_second_connection_waits_for_owner_lock_and_rechecks_the_committed_active_gate(): void
    {
        $this->gate('ready');
        // Publish fixtures so the second physical connection can see them. Restore
        // the ordinary test transaction and remove only these fixtures in finally.
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        $original = DB::getDefaultConnection();
        config(['database.connections.operational_gate_probe' => config('database.connections.mysql_testing')]);
        try {
            $probe = DB::connection('operational_gate_probe');
            $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $this->assertNotSame(DB::connection()->getPdo(), $probe->getPdo());
            DB::beginTransaction();
            AccountingPeriodLocks::owner($this->owner);
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update(['state' => 'active']);
            DB::setDefaultConnection('operational_gate_probe');
            try {
                (new PostTransaction)->handle($this->owner, $this->data());
                $this->fail('Create bypassed the held owner lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
                $this->assertStringContainsString('`users`', $exception->getSql());
                $this->assertStringContainsString('for update', strtolower($exception->getSql()));
            }
            $this->assertSame(0, DB::transactionLevel());
            DB::setDefaultConnection($original);
            $this->assertDatabaseCount('transactions', 0);
            $this->assertSame('100.00', $this->account->fresh()->balance);
            DB::commit();
            $committed = $this->integration();
            DB::setDefaultConnection('operational_gate_probe');
            try {
                (new PostTransaction)->handle($this->owner, $this->data());
                $this->fail('Create ignored the newly committed active gate.');
            } catch (AccountingConflict $exception) {
                $this->assertSame('Legacy transaction posting is unavailable for this integration state.', $exception->getMessage());
            }
            DB::setDefaultConnection($original);
            $this->assertSame($committed, $this->integration());
            $this->assertSame('100.00', $this->account->fresh()->balance);
            $this->assertDatabaseCount('transactions', 0);
            $this->assertNoAccountingWrites();
        } finally {
            DB::setDefaultConnection($original);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $probe = DB::connection('operational_gate_probe');
            while ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            DB::purge('operational_gate_probe');
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->delete();
            $this->cleanupBindingFixtures([$this->owner->id]);
            DB::table('transactions')->where('user_id', $this->owner->id)->delete();
            DB::table('accounts')->where('user_id', $this->owner->id)->delete();
            DB::table('categories')->where('user_id', $this->owner->id)->delete();
            DB::table('users')->where('id', $this->owner->id)->delete();
            DB::beginTransaction();
        }
    }
}
