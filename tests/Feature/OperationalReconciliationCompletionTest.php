<?php

namespace Tests\Feature;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Actions\PostTransaction;
use App\Models\User;
use App\OperationalAccounting\Actions\CompleteOperationalReconciliation;
use App\OperationalAccounting\OperationalReconciliationValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;
use Throwable;

class OperationalReconciliationCompletionTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function rejects(array $f, ?AccountingConflictReason $reason = null): void
    {
        try {
            $this->complete($f);
            $this->fail('Invalid completion accepted.');
        } catch (AccountingConflict $e) {
            if ($reason !== null) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $f['review'], 'reviewed_by' => null, 'completed_at' => null]);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public static function happyPaths(): array
    {
        return [['balance_only', false], ['historical_backfill', false], ['balance_only', true]];
    }

    #[DataProvider('happyPaths')]
    public function test_completion_seals_and_binds_only_permitted_fields(string $strategy, bool $empty): void
    {
        $f = $this->fixture($strategy, $empty);
        $before = $this->allFixtureRows($f['owner']->id);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12 10:11:12.123456', 'UTC'));
        try {
            $result = $this->complete($f);
        } finally {
            $this->travelBack();
        }
        $this->assertSame(['review_id' => $f['review'], 'reference' => $f['reference'], 'reviewed_by' => $f['owner']->id,
            'completed_at' => '2026-10-12 10:11:12.123456', 'integration_state' => 'reconciling'], $result);
        $after = $this->allFixtureRows($f['owner']->id);
        foreach ($before as $table => $rows) {
            if ($table === 'operational_reconciliation_reviews') {
                $rows[0]['reviewed_by'] = $result['reviewed_by'];
                $rows[0]['completed_at'] = $result['completed_at'];
            }
            if ($table === 'operational_accounting_integrations') {
                $rows[0]['reconciliation_reference'] = $result['reference'];
                $rows[0]['reconciled_at'] = $result['completed_at'];
            }
            $this->assertEquals($rows, $after[$table], $table);
        }
        try {
            $this->complete($f);
            $this->fail('Double completion accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationAlreadyCompleted, $e->reason);
        }
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['reconciliation_reference' => null, 'reconciled_at' => null]);
        try {
            $this->complete($f);
            $this->fail('Sealed review rebound.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationAlreadyCompleted, $e->reason);
        }
    }

    public static function staleChanges(): array
    {
        return [['scope'], ['account mapping'], ['category mapping'], ['activity'], ['inactive'], ['strategy'], ['currency'], ['boundary'], ['scope time'], ['digest']];
    }

    #[DataProvider('staleChanges')]
    public function test_stale_live_plan_or_review_decision_is_not_completed(string $change): void
    {
        $f = $this->fixture();
        $owner = $f['owner']->id;
        match ($change) {
            'scope' => DB::table('operational_accounting_category_scope')->where('user_id', $owner)->delete(),
            'account mapping' => DB::table('operational_account_mappings')->where('user_id', $owner)->delete(),
            'category mapping' => DB::table('operational_category_mappings')->where('user_id', $owner)->delete(),
            'activity' => DB::table('operational_category_mappings')->where('user_id', $owner)->update(['cash_flow_activity' => 'investing']),
            'inactive' => DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['is_active' => 0]),
            'strategy' => DB::table('operational_accounting_integrations')->where('user_id', $owner)->update(['historical_transaction_strategy' => 'historical_backfill']),
            'currency' => DB::table('operational_accounting_integrations')->where('user_id', $owner)->update(['accounting_currency' => 'USD']),
            'boundary' => DB::table('operational_accounting_integrations')->where('user_id', $owner)->update(['transaction_high_water_id' => 999]),
            'scope time' => DB::table('operational_accounting_integrations')->where('user_id', $owner)->update(['scope_reviewed_at' => '2026-10-10 09:00:01']),
            'digest' => DB::table('operational_reconciliation_accounts')->where('review_id', $f['review'])->update(['rationale' => 'Changed reviewer decision']),
        };
        $this->rejects($f);
    }

    public function test_legacy_create_before_completion_requires_explicit_recapture(): void
    {
        $f = $this->fixture('balance_only', true);
        (new PostTransaction)->handle($f['owner'], $this->postingData($f));
        $this->rejects($f, AccountingConflictReason::ReconciliationBoundaryStale);
        $this->assertSame(0, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('transaction_high_water_id'));
    }

    public function test_invalid_identities_authorization_and_nested_transaction_reject(): void
    {
        $f = $this->fixture();
        $other = User::factory()->create();
        $this->owners[] = $other->id;
        foreach ([[new User, $f['owner'], ModelNotFoundException::class], [$f['owner'], new User, ModelNotFoundException::class],
            [$f['owner'], $other, AuthorizationException::class]] as [$owner, $reviewer, $class]) {
            try {
                (new CompleteOperationalReconciliation)->execute($owner, $reviewer, $f['reference'], $f['digest']);
                $this->fail('Invalid identity accepted.');
            } catch (Throwable $e) {
                $this->assertInstanceOf($class, $e);
            }
        }
        DB::beginTransaction();
        try {
            $this->rejects($f, AccountingConflictReason::ReconciliationState);
        } finally {
            DB::rollBack();
        }
        $deleted = User::factory()->create();
        $deleted->delete();
        $deleted->exists = true;
        try {
            (new CompleteOperationalReconciliation)->execute($deleted, $deleted, $f['reference'], $f['digest']);
            $this->fail('Deleted owner accepted.');
        } catch (ModelNotFoundException $e) {
            $this->assertNotNull($e);
        }
    }

    public function test_failure_after_seal_and_during_binding_roll_back_both_writes(): void
    {
        foreach (['operational_reconciliation_reviews', 'operational_accounting_integrations'] as $table) {
            $f = $this->fixture();
            $connection = DB::connection();
            $old = $connection->getEventDispatcher();
            $events = new Dispatcher;
            $events->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event) use ($table): void {
                if (str_starts_with($event->sql, 'update `'.$table.'`')) {
                    throw new RuntimeException('Injected completion write failure');
                }
            });
            $connection->setEventDispatcher($events);
            try {
                $this->complete($f);
                $this->fail('Injected failure committed.');
            } catch (RuntimeException $e) {
                $this->assertSame('Injected completion write failure', $e->getMessage());
            } finally {
                $connection->setEventDispatcher($old);
            }
            $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $f['review'], 'completed_at' => null, 'reviewed_by' => null]);
            $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => null, 'reconciled_at' => null]);
        }
    }

    public function test_lock_order_sources_and_child_sets_precede_parent_and_validator_has_no_queries(): void
    {
        $f = $this->fixture();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $this->complete($f);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
        }
        $locks = array_values(array_filter(array_column($queries, 'query'), fn ($q) => preg_match('/for update|lock in share mode/i', $q)));
        $this->assertStringContainsString('`users`', $locks[0]);
        $parent = array_search(true, array_map(fn ($q) => str_contains($q, '`operational_reconciliation_reviews`') && str_contains($q, 'for update'), $locks), true);
        $this->assertNotFalse($parent);
        foreach (array_slice($locks, 1, $parent - 1) as $q) {
            $this->assertStringNotContainsString('update `', $q);
        }
        $this->assertStringContainsString('`operational_reconciliation_attributions`', $locks[$parent - 1]);
        foreach (array_slice($locks, $parent + 1) as $q) {
            $this->assertTrue(str_contains($q, '`operational_reconciliation_reviews`') || str_contains($q, '`operational_accounting_integrations`'));
        }
    }

    public function test_discovery_conflict_restarts_entire_transaction_without_sealing(): void
    {
        $f = $this->fixture();
        $validator = new class extends OperationalReconciliationValidator
        {
            public int $attempts = 0;

            public function validate(array $snapshot, string $reference, string $digest): void
            {
                if (++$this->attempts === 1) {
                    throw new AccountingConflict('Injected undiscovered dependency', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
                }
                parent::validate($snapshot, $reference, $digest);
            }
        };
        $this->complete($f, new CompleteOperationalReconciliation($validator));
        $this->assertSame(2, $validator->attempts);
    }

    public function test_two_connection_create_first_completion_reads_committed_boundary_staleness(): void
    {
        $f = $this->fixture('balance_only', true);
        DB::beginTransaction();
        (new PostTransaction)->handle($f['owner'], $this->postingData($f));
        try {
            $job = $this->startWorker($f, 'complete');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::commit();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame('ReconciliationBoundaryStale', $result['reason']);
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $f['review'], 'completed_at' => null]);
    }

    public function test_two_connection_completion_first_create_waits_then_invalidates_binding(): void
    {
        $f = $this->fixture('balance_only', true);
        $job = null;
        $connection = DB::connection();
        $old = $connection->getEventDispatcher();
        $events = new Dispatcher;
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event) use ($f, &$job): void {
            if (str_starts_with($event->sql, 'update `operational_accounting_integrations`')) {
                $job = $this->startWorker($f, 'post');
                $this->assertWorkerWaiting($job);
            }
        });
        $connection->setEventDispatcher($events);
        try {
            $this->complete($f);
        } finally {
            $connection->setEventDispatcher($old);
        }
        $this->assertNotNull($job);
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'reconciling',
            'transaction_high_water_id' => 0, 'reconciliation_reference' => null, 'reconciled_at' => null]);
        $this->assertNotNull(DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->value('completed_at'));
    }

    public function test_two_concurrent_completions_serialize_and_only_one_succeeds(): void
    {
        $f = $this->fixture();
        DB::beginTransaction();
        DB::table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
        try {
            $first = $this->startWorker($f, 'complete');
            $second = $this->startWorker($f, 'complete');
            $this->assertWorkerWaiting($first);
            $this->assertWorkerWaiting($second);
        } finally {
            DB::commit();
        }
        $results = [$this->waitEvent($first, 'result'), $this->waitEvent($second, 'result')];
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        $this->assertSame(['ReconciliationAlreadyCompleted'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')));
    }

    public function test_current_source_read_cannot_use_old_repeatable_read_snapshot(): void
    {
        $f = $this->fixture('balance_only', true);
        $this->configureProbeConnection('completion_probe');
        $connection = DB::connection();
        $changed = false;
        $currentTransactionRead = false;
        $ownerLockAttempts = 0;
        $primary = DB::getDefaultConnection();
        $connection->beforeExecuting(function ($sql) use ($f, &$changed, &$currentTransactionRead, &$ownerLockAttempts, $primary): void {
            if (str_contains($sql, 'from `transactions`') && str_contains($sql, 'lock in share mode')) {
                $currentTransactionRead = true;
            }
            if (str_contains($sql, '`users`') && str_contains($sql, 'for update')) {
                $ownerLockAttempts++;
            }
            if (! $changed && str_contains($sql, '`users`') && str_contains($sql, 'for update')) {
                $changed = true;
                // Establish a snapshot inside the action's transaction just before
                // owner X is acquired. The other connection then creates/commits
                // normally, before we obtain that owner lock.
                $this->assertSame(0, DB::table('transactions')->where('user_id', $f['owner']->id)->count());
                DB::setDefaultConnection('completion_probe');
                try {
                    // Factory models retain their original connection. Rehydrate
                    // here so the transactions() relation inserts on the probe,
                    // inside the same transaction as its owner/account locks.
                    $probeOwner = User::on('completion_probe')->findOrFail($f['owner']->id);
                    $transaction = (new PostTransaction)->handle($probeOwner, $this->postingData($f));
                    $this->assertSame('completion_probe', $transaction->getConnectionName());
                    $this->assertSame(0, DB::connection('completion_probe')->transactionLevel());
                    $this->assertSame(1, DB::connection('completion_probe')->table('transactions')->where('user_id', $f['owner']->id)->count());
                } finally {
                    DB::setDefaultConnection($primary);
                }
                $this->assertSame(0, DB::table('transactions')->where('user_id', $f['owner']->id)->count());
            }
        });
        try {
            $this->rejects($f, AccountingConflictReason::ReconciliationBoundaryStale);
        } finally {
            DB::setDefaultConnection($primary);
            DB::purge('completion_probe');
        }
        $this->assertTrue($changed);
        $this->assertTrue($currentTransactionRead);
        $this->assertSame(1, $ownerLockAttempts, 'The competing create must not cause a lock-wait retry that discards the old snapshot.');
    }

    public function test_child_first_completion_waits_and_rejects_changed_approval(): void
    {
        $f = $this->fixture();
        DB::beginTransaction();
        DB::table('operational_reconciliation_accounts')->where('review_id', $f['review'])->update(['rationale' => 'New committed decision']);
        try {
            $job = $this->startWorker($f, 'complete');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::commit();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame('ReconciliationDigestMismatch', $result['reason']);
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $f['review'], 'completed_at' => null]);
    }

    public function test_sealer_first_protects_children_then_child_rejects_sealed_parent(): void
    {
        $f = $this->fixture();
        $job = null;
        $connection = DB::connection();
        $old = $connection->getEventDispatcher();
        $events = new Dispatcher;
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event) use ($f, &$job): void {
            if (str_contains($event->sql, '`operational_reconciliation_reviews`') && str_contains($event->sql, 'for update')) {
                $job = $this->startWorker($f, 'child');
                $this->assertWorkerWaiting($job);
            }
        });
        $connection->setEventDispatcher($events);
        try {
            $this->complete($f);
        } finally {
            $connection->setEventDispatcher($old);
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame(1644, $result['code']);
        $this->assertStringContainsString('Sealed', $result['message']);
    }

    public function test_foreign_owner_history_does_not_affect_membership_and_owner_locks_are_independent(): void
    {
        $f = $this->fixture();
        $foreign = $this->fixture('historical_backfill');
        DB::beginTransaction();
        DB::table('users')->where('id', $foreign['owner']->id)->lockForUpdate()->first();
        try {
            $job = $this->startWorker($f, 'complete');
            $result = $this->waitEvent($job, 'result');
        } finally {
            DB::commit();
        }
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $foreign['review'], 'completed_at' => null]);
    }
}
