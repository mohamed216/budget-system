<?php

namespace Tests\Feature;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Actions\PostTransaction;
use App\Models\User;
use App\OperationalAccounting\Actions\MarkOperationalAccountingReady;
use App\OperationalAccounting\OperationalReconciliationValidator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithOperationalAccountingReadyFixtures;
use Tests\TestCase;

class OperationalAccountingReadyTest extends TestCase
{
    use InteractsWithOperationalAccountingReadyFixtures;

    private function ready(array $f, ?MarkOperationalAccountingReady $action = null): array
    {
        return ($action ?? new MarkOperationalAccountingReady)->execute($f['owner']);
    }

    private function rejectsReady(array $f, ?AccountingConflictReason $reason = null): void
    {
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->ready($f);
            $this->fail('Stale or invalid READY accepted.');
        } catch (AccountingConflict $exception) {
            if ($reason !== null) {
                $this->assertSame($reason, $exception->reason);
            }
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public static function freshPlans(): array
    {
        return [['balance_only', false], ['historical_backfill', false], ['balance_only', true]];
    }

    #[DataProvider('freshPlans')]
    public function test_fresh_completed_binding_becomes_ready_and_only_state_changes(string $strategy, bool $empty): void
    {
        $f = $this->boundFixture($strategy, $empty);
        $before = $this->allFixtureRows($f['owner']->id);
        $result = $this->ready($f);
        $gate = $before['operational_accounting_integrations'][0];
        $this->assertSame(['user_id' => $f['owner']->id, 'state' => 'ready',
            'reconciliation_reference' => $gate['reconciliation_reference'], 'reconciled_at' => $gate['reconciled_at']], $result);
        $before['operational_accounting_integrations'][0]['state'] = 'ready';
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->rejectsReady($f, AccountingConflictReason::ReconciliationState);
    }

    public static function invalidGates(): array
    {
        return [['absent'], ['legacy'], ['ready'], ['active'], ['unbound']];
    }

    #[DataProvider('invalidGates')]
    public function test_invalid_gate_rejects_without_writes(string $state): void
    {
        $f = $this->boundFixture();
        $query = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id);
        if ($state === 'absent') {
            $query->delete();
        } elseif (in_array($state, ['legacy', 'unbound'], true)) {
            $query->update(['state' => $state === 'legacy' ? 'legacy' : 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
        } else {
            $query->update(['state' => $state, 'activated_at' => $state === 'active' ? now('UTC')->format('Y-m-d H:i:s.u') : null]);
        }
        // Unknown states/half-bindings/foreign or incomplete bindings cannot be
        // persisted under 000005/000016. Their action validation is unit-tested,
        // rather than disabling the protections to manufacture those fixtures.
        $this->rejectsReady($f, AccountingConflictReason::ReconciliationState);
    }

    public static function staleSources(): array
    {
        return [['account scope'], ['category scope'], ['account mapping'], ['category mapping'],
            ['balance'], ['currency'], ['activity'], ['inactive'], ['posted membership']];
    }

    #[DataProvider('staleSources')]
    public function test_completed_binding_does_not_authorize_stale_live_sources(string $change): void
    {
        $f = $this->boundFixture();
        $owner = $f['owner']->id;
        match ($change) {
            'account scope' => DB::table('operational_accounting_account_scope')->where('user_id', $owner)->delete(),
            'category scope' => DB::table('operational_accounting_category_scope')->where('user_id', $owner)->delete(),
            'account mapping' => DB::table('operational_account_mappings')->where('user_id', $owner)->delete(),
            'category mapping' => DB::table('operational_category_mappings')->where('user_id', $owner)->delete(),
            'balance' => DB::table('accounts')->where('id', $f['account'])->update(['balance' => '100.01']),
            'currency' => DB::table('accounts')->where('id', $f['account'])->update(['currency' => 'USD']),
            'activity' => DB::table('operational_category_mappings')->where('user_id', $owner)->update(['cash_flow_activity' => 'investing']),
            'inactive' => DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['is_active' => 0]),
            'posted membership' => $this->addPostedHistory($f),
        };
        $this->rejectsReady($f);
    }

    private function addPostedHistory(array $f): void
    {
        $id = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id, 'entry_date' => '2026-10-12', 'currency' => config('accounting.currency')]);
        foreach ([$f['charts']['asset'], $f['charts']['equity']] as $number => $chart) {
            DB::table('journal_lines')->insert(['user_id' => $f['owner']->id, 'journal_entry_id' => $id,
                'chart_account_id' => $chart, 'line_number' => $number + 1, 'debit' => $number === 0 ? '0.01' : '0.00', 'credit' => $number === 1 ? '0.01' : '0.00']);
        }
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
    }

    public function test_unselected_records_and_foreign_owner_do_not_infer_participation(): void
    {
        $f = $this->boundFixture();
        $foreign = $this->boundFixture('historical_backfill');
        DB::table('accounts')->insert(['user_id' => $f['owner']->id, 'name' => 'Unselected', 'type' => 'bank', 'currency' => 'USD', 'balance' => '987.65']);
        DB::table('categories')->insert(['user_id' => $f['owner']->id, 'name' => 'Unselected', 'type' => 'expense']);
        $before = $this->allFixtureRows($foreign['owner']->id);
        DB::beginTransaction();
        DB::table('users')->where('id', $foreign['owner']->id)->lockForUpdate()->first();
        try {
            $job = $this->startWorker($f);
            $result = $this->waitEvent($job, 'result');
        } finally {
            DB::commit();
        }
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame($before, $this->allFixtureRows($foreign['owner']->id));
    }

    public function test_owner_transaction_above_boundary_rejects_even_if_binding_was_not_invalidated(): void
    {
        $f = $this->boundFixture();
        DB::table('transactions')->insert(['user_id' => $f['owner']->id] + $this->postingData($f));
        $this->rejectsReady($f, AccountingConflictReason::ReconciliationBoundaryStale);
    }

    public function test_current_source_and_evidence_locks_precede_review_and_only_state_is_written(): void
    {
        $f = $this->boundFixture();
        $queries = [];
        $connection = DB::connection();
        $old = $connection->getEventDispatcher();
        $events = new Dispatcher;
        $events->listen(QueryExecuted::class, function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $connection->setEventDispatcher($events);
        try {
            $this->ready($f);
        } finally {
            $connection->setEventDispatcher($old);
        }
        $locks = array_values(array_filter($queries, fn ($q) => preg_match('/for update|lock in share mode/i', $q)));
        $this->assertStringContainsString('`users`', $locks[0]);
        $parent = array_search(true, array_map(fn ($q) => str_contains($q, '`operational_reconciliation_reviews`') && str_contains($q, 'for update'), $locks), true);
        $this->assertNotFalse($parent);
        $this->assertStringContainsString('`operational_reconciliation_attributions`', $locks[$parent - 1]);
        foreach (array_slice($locks, $parent + 1) as $query) {
            $this->assertStringContainsString('`operational_accounting_integrations`', $query);
        }
        $writes = array_values(array_filter($queries, fn ($q) => preg_match('/^(?:insert|update|delete)\b/i', $q)));
        $this->assertSame(['update `operational_accounting_integrations` set `state` = ? where `user_id` = ? and `state` = ?'], $writes);
    }

    public function test_invalid_owner_and_nested_invocation_reject(): void
    {
        try {
            (new MarkOperationalAccountingReady)->execute(new User);
            $this->fail('Unsaved owner accepted.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
        $f = $this->boundFixture();
        DB::beginTransaction();
        try {
            $this->rejectsReady($f, AccountingConflictReason::ReconciliationState);
        } finally {
            DB::rollBack();
        }
    }

    public function test_ready_write_failure_rolls_back_without_changing_completed_evidence(): void
    {
        $f = $this->boundFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        $connection = DB::connection();
        $old = $connection->getEventDispatcher();
        $events = new Dispatcher;
        $events->listen(QueryExecuted::class, function ($event): void {
            if (str_starts_with($event->sql, 'update `operational_accounting_integrations`')) {
                throw new RuntimeException('Injected READY failure');
            }
        });
        $connection->setEventDispatcher($events);
        try {
            $this->ready($f);
            $this->fail('Injected write failure committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected READY failure', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($old);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_create_first_invalidates_binding_before_waiting_ready_can_authorize(): void
    {
        $f = $this->boundFixture('balance_only', true);
        DB::beginTransaction();
        (new PostTransaction)->handle($f['owner'], $this->postingData($f));
        try {
            $job = $this->startWorker($f);
            $this->assertWorkerWaiting($job);
        } finally {
            DB::commit();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame('ReconciliationState', $result['reason']);
    }

    public function test_ready_first_legacy_create_waits_then_demotes_and_invalidates(): void
    {
        $f = $this->boundFixture('balance_only', true);
        $job = null;
        $connection = DB::connection();
        $old = $connection->getEventDispatcher();
        $events = new Dispatcher;
        $events->listen(QueryExecuted::class, function ($event) use ($f, &$job): void {
            if (str_starts_with($event->sql, 'update `operational_accounting_integrations`')) {
                $job = $this->startWorker($f, 'post');
                $this->assertWorkerWaiting($job);
            }
        });
        $connection->setEventDispatcher($events);
        try {
            $this->ready($f);
        } finally {
            $connection->setEventDispatcher($old);
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'reconciling',
            'transaction_high_water_id' => 0, 'reconciliation_reference' => null, 'reconciled_at' => null]);
        $this->assertDatabaseHas('accounts', ['id' => $f['account'], 'balance' => '0.01']);
    }

    public function test_failed_legacy_create_preserves_ready_and_binding(): void
    {
        $f = $this->boundFixture();
        $this->ready($f);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostTransaction)->handle($f['owner'], array_replace($this->postingData($f), ['amount' => '10000000000000.00']));
            $this->fail('Overflow committed.');
        } catch (\Illuminate\Database\QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_two_ready_attempts_serialize_and_only_first_succeeds(): void
    {
        $f = $this->boundFixture();
        DB::beginTransaction();
        DB::table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
        try {
            $first = $this->startWorker($f);
            $second = $this->startWorker($f);
            $this->assertWorkerWaiting($first);
            $this->assertWorkerWaiting($second);
        } finally {
            DB::commit();
        }
        $results = [$this->waitEvent($first, 'result'), $this->waitEvent($second, 'result')];
        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
        $this->assertSame(['ReconciliationState'], array_values(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')));
    }

    public function test_old_repeatable_read_snapshot_cannot_hide_a_committed_balance_change(): void
    {
        $f = $this->boundFixture();
        config(['database.connections.ready_probe' => config('database.connections.mysql_testing')]);
        $changed = false;
        $attempts = 0;
        DB::connection()->beforeExecuting(function ($sql) use ($f, &$changed, &$attempts): void {
            if (str_contains($sql, '`users`') && str_contains($sql, 'for update')) {
                $attempts++;
                if (! $changed) {
                    $changed = true;
                    $this->assertSame('100.00', DB::table('accounts')->where('id', $f['account'])->value('balance'));
                    DB::connection('ready_probe')->transaction(function () use ($f): void {
                        DB::connection('ready_probe')->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                        DB::connection('ready_probe')->table('accounts')->where('id', $f['account'])->update(['balance' => '100.01']);
                    });
                    $this->assertSame(0, DB::connection('ready_probe')->transactionLevel());
                    $this->assertSame('100.01', DB::connection('ready_probe')->table('accounts')->where('id', $f['account'])->value('balance'));
                    $this->assertSame('100.00', DB::table('accounts')->where('id', $f['account'])->value('balance'));
                }
            }
        });
        try {
            // Baseline comparisons would legitimately change here; prove only
            // rejection and no READY update, while the other connection commits.
            $this->ready($f);
            $this->fail('Old snapshot authorized READY.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::ReconciliationSnapshotStale, $exception->reason);
        } finally {
            DB::purge('ready_probe');
        }
        $this->assertTrue($changed);
        $this->assertSame(1, $attempts);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'reconciling', 'reconciliation_reference' => $f['reference']]);
    }

    public function test_discovery_conflict_retries_only_the_entire_outer_transaction(): void
    {
        $f = $this->boundFixture();
        $validator = new class extends OperationalReconciliationValidator
        {
            public int $attempts = 0;

            public function validateBound(array $snapshot, string $expectedState): void
            {
                if (++$this->attempts === 1) {
                    throw new AccountingConflict('Injected manifest instability', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
                }
                parent::validateBound($snapshot, $expectedState);
            }
        };
        $this->ready($f, new MarkOperationalAccountingReady($validator));
        $this->assertSame(2, $validator->attempts);
    }
}
