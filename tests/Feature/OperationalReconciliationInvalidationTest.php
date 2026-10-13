<?php

namespace Tests\Feature;

use App\Accounting\Exceptions\AccountingConflict;
use App\Actions\PostTransaction;
use App\OperationalAccounting\Actions\MapOperationalAccount;
use App\OperationalAccounting\Actions\MapOperationalCategory;
use App\OperationalAccounting\Actions\MarkOperationalAccountingReady;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OperationalReconciliationInvalidationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function replacement(array $f, string $type): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id,
            'code' => 'REPLACEMENT_'.$type, 'name' => 'Replacement', 'type' => $type,
            'cash_role' => 'non_cash', 'is_active' => 1]);
    }

    private function mutate(array $f, string $kind): void
    {
        if ($kind === 'account') {
            (new MapOperationalAccount)->execute($f['owner'], $f['account'], $f['mapping_target']);
        } else {
            (new MapOperationalCategory)->execute($f['owner'], $f['category'],
                $kind === 'activity' ? $f['charts']['revenue'] : $f['mapping_target'],
                $kind === 'activity' ? 'investing' : 'operating');
        }
    }

    public static function mappingChanges(): array
    {
        return [['account', false], ['account', true], ['category', false], ['category', true], ['activity', false], ['activity', true]];
    }

    #[DataProvider('mappingChanges')]
    public function test_selected_change_clears_only_binding_and_demotes_ready(string $kind, bool $ready): void
    {
        $f = $this->boundFixture();
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        if ($ready) {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $this->mutate($f, $kind);
        $after = $this->allFixtureRows($f['owner']->id);
        $gate = $after['operational_accounting_integrations'][0];
        $this->assertSame('reconciling', $gate['state']);
        $this->assertNull($gate['reconciliation_reference']);
        $this->assertNull($gate['reconciled_at']);
        foreach (['historical_transaction_strategy', 'accounting_currency', 'transaction_high_water_id', 'scope_reviewed_at', 'activated_at'] as $field) {
            $this->assertSame($before['operational_accounting_integrations'][0][$field], $gate[$field]);
        }
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['operational_accounting_integrations', $kind === 'account' ? 'operational_account_mappings' : 'operational_category_mappings'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $this->assertDatabaseHas($kind === 'account' ? 'operational_account_mappings' : 'operational_category_mappings',
            ['user_id' => $f['owner']->id, 'chart_account_id' => $kind === 'activity' ? $f['charts']['revenue'] : $f['mapping_target']]);
        if ($kind === 'activity') {
            $this->assertDatabaseHas('operational_category_mappings', ['category_id' => $f['category'], 'cash_flow_activity' => 'investing']);
        }
    }

    public static function recordKinds(): array
    {
        return [['account'], ['category']];
    }

    #[DataProvider('recordKinds')]
    public function test_selected_mapping_without_gate_does_not_create_one(string $kind): void
    {
        $f = $this->fixture();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->delete();
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $this->mutate($f, $kind);
        $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $f['owner']->id]);
        $this->assertDatabaseHas('operational_'.$kind.'_mappings', ['user_id' => $f['owner']->id, 'chart_account_id' => $f['mapping_target']]);
    }

    #[DataProvider('recordKinds')]
    public function test_unbound_reconciling_mapping_keeps_gate_unchanged(string $kind): void
    {
        $f = $this->fixture();
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $before = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->mutate($f, $kind);
        $this->assertEquals($before, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    #[DataProvider('recordKinds')]
    public function test_outside_scope_mapping_does_not_invalidate_even_with_balance(string $kind): void
    {
        $f = $this->boundFixture('balance_only', true);
        DB::table('accounts')->where('id', $f['account'])->update(['balance' => '500.00']);
        $before = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $f['mapping_target'] = $f['charts'][$kind === 'account' ? 'asset' : 'revenue'];
        $this->mutate($f, $kind);
        $this->assertEquals($before, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $this->mutate($f, $kind);
        $this->assertEquals($before, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    #[DataProvider('recordKinds')]
    public function test_same_classification_preserves_binding_and_completed_review_metadata(string $kind): void
    {
        $f = $this->boundFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $f['mapping_target'] = $f['charts'][$kind === 'account' ? 'asset' : 'revenue'];
        $before = $this->allFixtureRows($f['owner']->id);
        $this->mutate($f, $kind);
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    #[DataProvider('recordKinds')]
    public function test_noop_still_validates_target_activity(string $kind): void
    {
        $f = $this->boundFixture();
        $f['mapping_target'] = $f['charts'][$kind === 'account' ? 'asset' : 'revenue'];
        DB::table('chart_of_accounts')->where('id', $f['mapping_target'])->update(['is_active' => 0]);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->mutate($f, $kind);
            $this->fail('Invalid target accepted as no-op.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
    }

    #[DataProvider('recordKinds')]
    public function test_linked_use_freeze_rejects_without_invalidating(string $kind): void
    {
        $f = $this->boundFixture('historical_backfill');
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->mutate($f, $kind);
            $this->fail('Frozen mapping changed.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
            $this->assertSame(1644, $e->errorInfo[1]);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_linked_category_activity_is_frozen_without_invalidating(): void
    {
        $f = $this->boundFixture('historical_backfill');
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->mutate($f, 'activity');
            $this->fail('Frozen category activity changed.');
        } catch (QueryException $e) {
            $this->assertSame('45000', $e->errorInfo[0]);
            $this->assertSame(1644, $e->errorInfo[1]);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public static function writeFailures(): array
    {
        return [['account', 'mapping'], ['account', 'invalidation'], ['category', 'mapping'], ['category', 'invalidation']];
    }

    #[DataProvider('writeFailures')]
    public function test_failure_after_either_write_rolls_back_both(string $kind, string $stage): void
    {
        $f = $this->boundFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $before = $this->allFixtureRows($f['owner']->id);
        $table = $stage === 'invalidation' ? 'operational_accounting_integrations' : 'operational_'.$kind.'_mappings';
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use ($table): void {
            if (str_starts_with($query->sql, 'update `'.$table.'`')) {
                throw new RuntimeException('Injected after '.$table);
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->mutate($f, $kind);
            $this->fail('Injected write failure committed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected after '.$table, $e->getMessage());
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    #[DataProvider('recordKinds')]
    public function test_active_relevant_change_and_foreign_target_reject_without_mutation(string $kind): void
    {
        $f = $this->boundFixture();
        $foreign = $this->boundFixture();
        $f['mapping_target'] = $foreign['charts'][$kind === 'account' ? 'asset' : 'revenue'];
        $before = $this->allFixtureRows($f['owner']->id);
        $foreignBefore = $this->allFixtureRows($foreign['owner']->id);
        try {
            $this->mutate($f, $kind);
            $this->fail('Foreign chart accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['state' => 'active', 'activated_at' => now()]);
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->mutate($f, $kind);
            $this->fail('Active integration invalidated.');
        } catch (AccountingConflict) {
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
        $this->assertSame($foreignBefore, $this->allFixtureRows($foreign['owner']->id));
    }

    public static function postingStates(): array
    {
        return [['reconciling', false], ['ready', false], ['reconciling', true]];
    }

    #[DataProvider('postingStates')]
    public function test_posting_keeps_existing_atomic_invalidation_and_boundary(string $state, bool $unbound): void
    {
        $f = $unbound ? $this->fixture() : $this->boundFixture();
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        (new PostTransaction)->handle($f['owner'], $this->postingData($f));
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'reconciling',
            'reconciliation_reference' => null, 'reconciled_at' => null, 'transaction_high_water_id' => 0]);
        $this->assertDatabaseHas('accounts', ['id' => $f['account'], 'balance' => '100.01']);
    }

    public function test_failed_posting_and_active_posting_preserve_everything(): void
    {
        $f = $this->boundFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostTransaction)->handle($f['owner'], array_replace($this->postingData($f), ['amount' => '10000000000000.00']));
            $this->fail('Overflow committed.');
        } catch (QueryException) {
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => now()]);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostTransaction)->handle($f['owner'], $this->postingData($f));
            $this->fail('Active legacy write committed.');
        } catch (AccountingConflict) {
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
    }

    #[DataProvider('recordKinds')]
    public function test_mapping_lock_order(string $kind): void
    {
        $f = $this->boundFixture();
        $f['mapping_target'] = $this->replacement($f, $kind === 'account' ? 'asset' : 'revenue');
        $locks = [];
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update') && preg_match('/from `([^`]+)`/', $query->sql, $match)) {
                $locks[] = $match[1];
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->mutate($f, $kind);
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertSame(['users', $kind === 'account' ? 'accounts' : 'categories', 'operational_accounting_integrations',
            'operational_accounting_'.$kind.'_scope', 'operational_'.$kind.'_mappings', 'chart_of_accounts'], $locks);
    }

    public function test_mapping_first_makes_waiting_ready_reject(): void
    {
        $f = $this->boundFixture();
        $f['mapping_target'] = $this->replacement($f, 'asset');
        DB::beginTransaction();
        try {
            $this->mutate($f, 'account');
            $job = $this->startWorker($f, 'ready');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::commit();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame('ReconciliationState', $result['reason']);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_ready_first_mapping_waits_then_demotes(): void
    {
        $f = $this->boundFixture();
        $f['mapping_target'] = $this->replacement($f, 'asset');
        $job = null;
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) use ($f, &$job): void {
            if (str_starts_with($query->sql, 'update `operational_accounting_integrations`') && $job === null) {
                $job = $this->startWorker($f, 'map-account');
                $this->assertWorkerWaiting($job);
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertNotNull($job);
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_other_owner_lock_does_not_block_mapping(): void
    {
        $f = $this->boundFixture();
        $foreign = $this->boundFixture();
        $f['mapping_target'] = $this->replacement($f, 'asset');
        $before = $this->allFixtureRows($foreign['owner']->id);
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $foreign['owner']->id)->lockForUpdate()->first();
            $job = $this->startWorker($f, 'map-account');
            $result = $this->waitEvent($job, 'result');
            $this->assertTrue($result['ok'], $result['message'] ?? '');
        } finally {
            DB::commit();
        }
        $this->assertSame($before, $this->allFixtureRows($foreign['owner']->id));
    }

    public function test_scope_relevance_uses_current_read_not_old_repeatable_read_snapshot(): void
    {
        $f = $this->boundFixture('balance_only', true);
        $f['mapping_target'] = $this->replacement($f, 'asset');
        $this->configureProbeConnection('invalidation_probe');
        $changed = false;
        $attempts = 0;
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $originalCallbacks = $callbacks->getValue($connection);
        DB::connection()->beforeExecuting(function ($sql) use ($f, &$changed, &$attempts): void {
            if (str_contains($sql, '`users`') && str_contains($sql, 'for update')) {
                $attempts++;
                if (! $changed) {
                    $changed = true;
                    $this->assertFalse(DB::table('operational_accounting_account_scope')->where('account_id', $f['account'])->exists());
                    DB::connection('invalidation_probe')->transaction(function () use ($f): void {
                        DB::connection('invalidation_probe')->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                        DB::connection('invalidation_probe')->table('operational_accounting_account_scope')
                            ->insert(['user_id' => $f['owner']->id, 'account_id' => $f['account']]);
                    });
                    $this->assertTrue(DB::connection('invalidation_probe')->table('operational_accounting_account_scope')->where('account_id', $f['account'])->exists());
                    $this->assertFalse(DB::table('operational_accounting_account_scope')->where('account_id', $f['account'])->exists());
                }
            }
        });
        try {
            $this->mutate($f, 'account');
        } finally {
            $callbacks->setValue($connection, $originalCallbacks);
        }
        $this->assertTrue($changed);
        $this->assertSame(1, $attempts, 'No retry may conceal current-read authority.');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }
}
