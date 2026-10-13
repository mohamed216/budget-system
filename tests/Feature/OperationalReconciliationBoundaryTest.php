<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Actions\PostTransaction;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\OperationalAccounting\Actions\BeginOperationalReconciliation;
use App\OperationalAccounting\Actions\CaptureOperationalReconciliationBoundary;
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

class OperationalReconciliationBoundaryTest extends TestCase
{
    use CreatesCompletedReconciliationBindings;
    use RefreshFinancialDatabase;

    private User $owner;

    private Account $account;

    private Category $category;

    private array $fixtureOwnerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->newOwner();
        $this->account = $this->owner->accounts()->create(['name' => 'Account', 'type' => 'bank', 'balance' => '100.00']);
        $this->category = $this->owner->categories()->create(['name' => 'Income', 'type' => 'income']);
    }

    private function newOwner(): User
    {
        $owner = User::factory()->create();
        $this->fixtureOwnerIds[] = $owner->id;

        return $owner;
    }

    private function postOperationalTransaction(): int
    {
        // Factory-created models retain their original connection. Bind the actor
        // to the selected physical connection so its hasMany insert joins that
        // connection's transaction, just as it does in a normal request.
        $owner = clone $this->owner;
        $owner->setConnection(DB::getDefaultConnection());

        return (new PostTransaction)->handle($owner, ['account_id' => $this->account->id,
            'category_id' => $this->category->id, 'amount' => '0.10', 'type' => 'income',
            'date' => '2026-10-12', 'description' => 'Legacy create'])->id;
    }

    private function action(string $kind): BeginOperationalReconciliation|CaptureOperationalReconciliationBoundary
    {
        return $kind === 'begin' ? new BeginOperationalReconciliation : new CaptureOperationalReconciliationBoundary;
    }

    private function gate(string $state = 'reconciling', ?User $owner = null): void
    {
        DB::table('operational_accounting_integrations')->insert($this->completedBindingFixture([
            'user_id' => ($owner ?? $this->owner)->id, 'state' => $state,
            'historical_transaction_strategy' => 'balance_only', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 0, 'reconciliation_reference' => 'Reviewed evidence',
            'reconciled_at' => '2026-10-10 10:00:00', 'scope_reviewed_at' => '2026-10-10 09:00:00',
            'activated_at' => '2026-10-10 11:00:00', 'created_at' => '2026-10-10 08:00:00',
            'updated_at' => '2026-10-10 08:00:00',
        ]));
    }

    private function row(): array
    {
        return (array) DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->first();
    }

    private function inventory(): array
    {
        $result = [];
        foreach (['accounts', 'categories', 'transactions', 'operational_accounting_integrations',
            'operational_accounting_account_scope', 'operational_accounting_category_scope',
            'operational_account_mappings', 'operational_category_mappings', 'chart_of_accounts',
            'journal_entries', 'journal_lines', 'operational_transaction_journals', 'opening_balance_batches'] as $table) {
            $result[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public static function actions(): array
    {
        return [['begin'], ['capture']];
    }

    #[DataProvider('actions')]
    public function test_invalid_owner_is_rejected_before_any_query(string $kind): void
    {
        DB::table('accounts')->insert(['user_id' => null, 'name' => 'Unassigned', 'type' => 'bank', 'balance' => '20.00']);
        $unsavedWithKey = new User;
        $unsavedWithKey->id = $this->owner->id;
        $missingKey = clone $this->owner;
        $missingKey->id = null;
        $zeroKey = clone $this->owner;
        $zeroKey->id = 0;
        $before = $this->inventory();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            foreach ([new User, $unsavedWithKey, $missingKey, $zeroKey] as $owner) {
                try {
                    $this->action($kind)->execute($owner);
                    $this->fail('Invalid owner accepted.');
                } catch (ModelNotFoundException $exception) {
                    $this->assertSame(User::class, $exception->getModel());
                }
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($before, $this->inventory());
    }

    #[DataProvider('actions')]
    public function test_deleted_persisted_owner_is_rejected_by_owner_lock(string $kind): void
    {
        $missing = clone $this->owner;
        $missing->id = 9223372036854775807;
        $before = $this->inventory();
        try {
            $this->action($kind)->execute($missing);
            $this->fail('Missing owner accepted.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(User::class, $exception->getModel());
        }
        $this->assertSame($before, $this->inventory());
    }

    public function test_absent_gate_begin_captures_zero_without_populating_a_plan_or_other_data(): void
    {
        $before = $this->inventory();
        $row = (new BeginOperationalReconciliation)->execute($this->owner);
        $this->assertSame('reconciling', $row['state']);
        $this->assertSame($this->owner->id, $row['user_id']);
        $this->assertSame(0, $row['transaction_high_water_id']);
        foreach (['historical_transaction_strategy', 'accounting_currency', 'scope_reviewed_at',
            'reconciliation_reference', 'reconciled_at', 'activated_at'] as $field) {
            $this->assertNull($row[$field], $field);
        }
        $after = $this->inventory();
        unset($before['operational_accounting_integrations'], $after['operational_accounting_integrations']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('operational_accounting_integrations', 1);
    }

    #[DataProvider('actions')]
    public function test_boundary_uses_owner_max_id_not_date_foreign_or_unassigned_history(string $kind): void
    {
        if ($kind === 'capture') {
            $this->gate();
        }
        $first = $this->postOperationalTransaction();
        $last = $this->postOperationalTransaction();
        DB::table('transactions')->where('id', $last)->update(['date' => '2000-01-01']);
        $other = $this->newOwner();
        $foreignAccount = $other->accounts()->create(['name' => 'Foreign', 'type' => 'bank', 'balance' => '0.00']);
        $foreignCategory = $other->categories()->create(['name' => 'Foreign', 'type' => 'income']);
        DB::table('transactions')->insert(['user_id' => $other->id, 'account_id' => $foreignAccount->id,
            'category_id' => $foreignCategory->id, 'type' => 'income', 'amount' => '1.00', 'date' => '2026-10-12']);
        DB::table('transactions')->insert(['user_id' => null, 'account_id' => $this->account->id,
            'category_id' => $this->category->id, 'type' => 'income', 'amount' => '1.00', 'date' => '2026-10-12']);
        $before = $this->inventory();
        $row = $this->action($kind)->execute($this->owner);
        $this->assertGreaterThan($first, $last);
        $this->assertSame($last, $row['transaction_high_water_id']);
        $after = $this->inventory();
        unset($before['operational_accounting_integrations'], $after['operational_accounting_integrations']);
        $this->assertSame($before, $after);
    }

    public function test_legacy_begin_preserves_reviewed_plan_scopes_mappings_and_classification(): void
    {
        $id = $this->postOperationalTransaction();
        $this->gate('legacy');
        $chart = DB::table('chart_of_accounts')->insertGetId(['user_id' => $this->owner->id,
            'code' => 'ASSET', 'name' => 'Asset', 'type' => 'asset', 'cash_role' => 'non_cash', 'is_active' => true]);
        DB::table('operational_accounting_account_scope')->insert(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
        DB::table('operational_accounting_category_scope')->insert(['category_id' => $this->category->id, 'user_id' => $this->owner->id]);
        DB::table('operational_account_mappings')->insert(['account_id' => $this->account->id,
            'user_id' => $this->owner->id, 'chart_account_id' => $chart]);
        $revenue = DB::table('chart_of_accounts')->insertGetId(['user_id' => $this->owner->id,
            'code' => 'REVENUE', 'name' => 'Revenue', 'type' => 'revenue', 'cash_role' => 'non_cash', 'is_active' => true]);
        DB::table('operational_category_mappings')->insert(['category_id' => $this->category->id,
            'user_id' => $this->owner->id, 'chart_account_id' => $revenue, 'cash_flow_activity' => 'operating',
            'reviewed_by' => $this->owner->id, 'reviewed_at' => '2026-10-10 09:00:00']);
        $before = $this->inventory();
        $old = $this->row();
        $row = (new BeginOperationalReconciliation)->execute($this->owner);
        $this->assertSame('reconciling', $row['state']);
        $this->assertSame($id, $row['transaction_high_water_id']);
        $this->assertNull($row['reconciliation_reference']);
        $this->assertNull($row['reconciled_at']);
        $this->assertPlanPreserved($old, $row);
        $after = $this->inventory();
        unset($before['operational_accounting_integrations'], $after['operational_accounting_integrations']);
        $this->assertSame($before, $after);

        // Explicit recapture also preserves the entire reviewed plan.
        $newId = $this->postOperationalTransaction();
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)
            ->update($this->completedBindingFixture(array_replace($this->row(), ['reconciled_at' => '2026-10-12 10:00:00'])));
        $before = $this->inventory();
        $row = (new CaptureOperationalReconciliationBoundary)->execute($this->owner);
        $this->assertSame($newId, $row['transaction_high_water_id']);
        $this->assertNull($row['reconciliation_reference']);
        $this->assertNull($row['reconciled_at']);
        $this->assertPlanPreserved($old, $row);
        $after = $this->inventory();
        unset($before['operational_accounting_integrations'], $after['operational_accounting_integrations']);
        $this->assertSame($before, $after);
    }

    private function assertPlanPreserved(array $before, array $after): void
    {
        foreach (['user_id', 'historical_transaction_strategy', 'accounting_currency',
            'scope_reviewed_at', 'activated_at', 'created_at'] as $field) {
            $this->assertSame($before[$field], $after[$field], $field);
        }
    }

    public function test_reconciling_begin_is_a_true_no_op_even_after_new_creates(): void
    {
        $this->gate();
        $before = $this->row();
        $this->assertSame($before, (new BeginOperationalReconciliation)->execute($this->owner));
        $this->postOperationalTransaction();
        $before = $this->inventory();
        $this->travel(1)->minutes();
        $row = (new BeginOperationalReconciliation)->execute($this->owner);
        $this->assertSame(0, $row['transaction_high_water_id']);
        $this->assertSame($before, $this->inventory());
    }

    public static function rejectedStates(): array
    {
        return [['begin', 'ready'], ['begin', 'active'], ['capture', null],
            ['capture', 'legacy'], ['capture', 'ready'], ['capture', 'active']];
    }

    #[DataProvider('rejectedStates')]
    public function test_disallowed_state_is_rejected_without_changes(string $kind, ?string $state): void
    {
        if ($state !== null) {
            $this->gate($state);
        }
        $before = $this->inventory();
        try {
            $this->action($kind)->execute($this->owner);
            $this->fail('Disallowed state accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame($before, $this->inventory());
    }

    #[DataProvider('actions')]
    public function test_unknown_gate_state_fails_closed_without_weakening_checks(string $kind): void
    {
        $before = $this->inventory();
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
                $this->action($kind)->execute($this->owner);
                $this->fail('Unknown state accepted.');
            } catch (AccountingConflict $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        } finally {
            DB::swap($manager);
        }
        $this->assertSame($before, $this->inventory());
    }

    public function test_capture_null_boundary_as_zero_then_unchanged_capture_is_a_true_no_op(): void
    {
        $this->gate();
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update([
            'transaction_high_water_id' => null, 'reconciliation_reference' => null, 'reconciled_at' => null,
        ]);
        $row = (new CaptureOperationalReconciliationBoundary)->execute($this->owner);
        $this->assertSame(0, $row['transaction_high_water_id']);
        $this->assertNull($row['reconciliation_reference']);
        $this->assertNull($row['reconciled_at']);
        // Even completion evidence is retained when the explicit boundary has not changed.
        DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)
            ->update($this->completedBindingFixture(array_replace($this->row(), ['reconciled_at' => '2026-10-12 10:00:00'])));
        $before = $this->inventory();
        $this->travel(1)->minutes();
        $this->assertSame($this->row(), (new CaptureOperationalReconciliationBoundary)->execute($this->owner));
        $this->assertSame($before, $this->inventory());
    }

    #[DataProvider('actions')]
    public function test_lock_order_is_owner_gate_then_latest_owner_transaction(string $kind): void
    {
        $this->postOperationalTransaction();
        if ($kind === 'capture') {
            $this->gate();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->action($kind)->execute($this->owner);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $locks = collect($queries)->filter(fn ($query) => str_contains(strtolower($query['query']), 'for update'))->values();
        $this->assertCount(3, $locks);
        foreach (['users', 'operational_accounting_integrations', 'transactions'] as $index => $table) {
            $this->assertStringContainsString('`'.$table.'`', $locks[$index]['query']);
            $this->assertContains($this->owner->id, $locks[$index]['bindings']);
        }
        $this->assertStringContainsString('`users`', $queries[0]['query']);
        $this->assertStringContainsString('order by `id` desc limit 1', strtolower($locks[2]['query']));
        $this->assertStringNotContainsString('max(', strtolower($locks[2]['query']));
    }

    public static function failurePaths(): array
    {
        return [['begin', null], ['begin', 'legacy'], ['capture', 'reconciling']];
    }

    #[DataProvider('failurePaths')]
    public function test_failure_after_gate_write_rolls_back_all_changes(string $kind, ?string $state): void
    {
        $this->postOperationalTransaction();
        if ($state !== null) {
            $this->gate($state);
        }
        $before = $this->inventory();
        $level = DB::transactionLevel();
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        $observed = null;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$observed) {
            if (preg_match('/^(insert into|update) `operational_accounting_integrations`/i', $event->sql)) {
                $observed = $this->row();
                throw new RuntimeException('Injected reconciliation write failure.');
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            try {
                $this->action($kind)->execute($this->owner);
                $this->fail('Injected failure ignored.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected reconciliation write failure.', $exception->getMessage());
            }
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertNotNull($observed);
        $this->assertSame('reconciling', $observed['state']);
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->inventory());
    }

    /** Use only the isolated mysql_testing database; restore RefreshDatabase's outer transaction. */
    private function withTwoConnections(callable $test): void
    {
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        $original = DB::getDefaultConnection();
        config(['database.connections.reconciliation_probe' => config('database.connections.mysql_testing')]);
        try {
            $probe = DB::connection('reconciliation_probe');
            $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $this->assertNotSame(DB::connection()->getPdo(), $probe->getPdo());
            $test($original);
        } finally {
            DB::setDefaultConnection($original);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $probe = DB::connection('reconciliation_probe');
            while ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            DB::purge('reconciliation_probe');
            foreach (['operational_accounting_integrations', 'transactions', 'accounts', 'categories'] as $table) {
                DB::table($table)->whereIn('user_id', $this->fixtureOwnerIds)->delete();
            }
            $this->cleanupBindingFixtures($this->fixtureOwnerIds);
            DB::table('users')->whereIn('id', $this->fixtureOwnerIds)->delete();
            DB::beginTransaction();
        }
    }

    private function assertWaitsOnOwner(callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Operation bypassed the held owner lock.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1]);
            $this->assertStringContainsString('`users`', $exception->getSql());
            $this->assertStringContainsString('for update', strtolower($exception->getSql()));
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('actions')]
    public function test_create_commits_first_and_waiting_begin_or_capture_includes_it(string $kind): void
    {
        if ($kind === 'capture') {
            $this->gate();
        }
        $this->withTwoConnections(function (string $primary) use ($kind) {
            DB::beginTransaction();
            $id = $this->postOperationalTransaction();
            DB::setDefaultConnection('reconciliation_probe');
            $this->assertWaitsOnOwner(fn () => $this->action($kind)->execute($this->owner));
            DB::setDefaultConnection($primary);
            DB::commit();
            DB::setDefaultConnection('reconciliation_probe');
            $row = $this->action($kind)->execute($this->owner);
            $this->assertSame($id, $row['transaction_high_water_id']);
            $this->assertSame('reconciling', $row['state']);
        });
    }

    #[DataProvider('actions')]
    public function test_boundary_commits_first_and_waiting_create_does_not_advance_it(string $kind): void
    {
        if ($kind === 'capture') {
            $this->gate();
            DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)->update([
                'transaction_high_water_id' => null, 'reconciliation_reference' => null, 'reconciled_at' => null,
            ]);
        }
        $this->withTwoConnections(function (string $primary) use ($kind) {
            DB::beginTransaction();
            $row = $this->action($kind)->execute($this->owner);
            DB::setDefaultConnection('reconciliation_probe');
            $this->assertWaitsOnOwner(fn () => $this->postOperationalTransaction());
            DB::setDefaultConnection($primary);
            DB::commit();
            DB::setDefaultConnection('reconciliation_probe');
            $id = $this->postOperationalTransaction();
            $this->assertGreaterThan($row['transaction_high_water_id'], $id);
            $this->assertSame($row['transaction_high_water_id'], $this->row()['transaction_high_water_id']);
            $this->assertSame('100.10', DB::table('accounts')->where('id', $this->account->id)->value('balance'));
        });
    }

    public function test_concurrent_begin_calls_create_one_gate_and_do_not_recapture(): void
    {
        $id = $this->postOperationalTransaction();
        $this->withTwoConnections(function (string $primary) use ($id) {
            DB::beginTransaction();
            $first = (new BeginOperationalReconciliation)->execute($this->owner);
            DB::setDefaultConnection('reconciliation_probe');
            $this->assertWaitsOnOwner(fn () => (new BeginOperationalReconciliation)->execute($this->owner));
            DB::setDefaultConnection($primary);
            DB::commit();
            // A create after the first begin must not be silently captured by the second begin.
            $this->postOperationalTransaction();
            $before = $this->row();
            DB::setDefaultConnection('reconciliation_probe');
            $this->assertSame($before, (new BeginOperationalReconciliation)->execute($this->owner));
            $this->assertSame($id, $first['transaction_high_water_id']);
            $this->assertSame($id, $this->row()['transaction_high_water_id']);
            $this->assertDatabaseCount('operational_accounting_integrations', 1);
        });
    }

    #[DataProvider('actions')]
    public function test_current_locking_reads_ignore_an_older_repeatable_read_snapshot(string $kind): void
    {
        if ($kind === 'capture') {
            $this->gate();
        }
        $this->withTwoConnections(function (string $primary) use ($kind) {
            DB::setDefaultConnection('reconciliation_probe');
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();
            $this->assertSame(0, DB::table('transactions')->where('user_id', $this->owner->id)->count());
            $oldGate = $this->row(); // Establish a stale gate snapshot as well.
            DB::setDefaultConnection($primary);
            $id = $this->postOperationalTransaction();
            if ($kind === 'begin') {
                $this->gate('legacy');
            } else {
                DB::table('operational_accounting_integrations')->where('user_id', $this->owner->id)
                    ->update($this->completedBindingFixture(array_replace($this->row(), ['reconciled_at' => '2026-10-12 10:00:00'])));
            }
            DB::setDefaultConnection('reconciliation_probe');
            $this->assertSame(0, DB::table('transactions')->where('user_id', $this->owner->id)->count());
            $this->assertSame($oldGate, $this->row());
            $row = $this->action($kind)->execute($this->owner);
            $this->assertSame($id, $row['transaction_high_water_id']);
            $this->assertSame('reconciling', $row['state']);
            $this->assertNull($row['reconciliation_reference']);
            $this->assertNull($row['reconciled_at']);
            DB::commit();
        });
    }

    #[DataProvider('actions')]
    public function test_another_owner_can_begin_or_capture_while_this_owner_is_locked(string $kind): void
    {
        $other = $this->newOwner();
        if ($kind === 'capture') {
            $this->gate(owner: $other);
        }
        $this->withTwoConnections(function (string $primary) use ($other, $kind) {
            DB::beginTransaction();
            AccountingPeriodLocks::owner($this->owner);
            DB::setDefaultConnection('reconciliation_probe');
            $row = $this->action($kind)->execute($other);
            $this->assertSame($other->id, $row['user_id']);
            $this->assertSame(0, $row['transaction_high_water_id']);
            $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $this->owner->id]);
            DB::setDefaultConnection($primary);
            DB::rollBack();
        });
    }
}
