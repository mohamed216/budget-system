<?php

namespace Tests\Feature;

use App\Accounting\Actions\ReviewCashAccountRole;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\ChartAccount;
use App\OperationalAccounting\Actions\MarkOperationalAccountingReady;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OperationalReconciliationChartInvalidationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function updateChart(array $f, int $id, array $changes = []): ChartAccount
    {
        $chart = ChartAccount::ownedBy($f['owner'])->findOrFail($id);
        $fields = array_replace(['code' => $chart->code, 'name' => $chart->name, 'type' => $chart->type,
            'active' => $chart->is_active, 'parent' => $chart->parent_id], $changes);

        return (new UpdateChartAccount)->execute($f['owner'], $id, $fields['code'], $fields['name'],
            $fields['type'], $fields['active'], $fields['parent']);
    }

    private function extraChart(array $f, string $code = 'UNRELATED', ?string $role = null): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id, 'code' => $code,
            'name' => $code, 'type' => 'asset', 'is_active' => 1, 'cash_role' => $role]);
    }

    private function zeroParticipant(): array
    {
        $f = $this->fixture('balance_only', true);
        $base = ['review_id' => $f['review'], 'user_id' => $f['owner']->id];
        DB::table('operational_accounting_account_scope')->insert(['account_id' => $f['account'], 'user_id' => $f['owner']->id]);
        DB::table('operational_account_mappings')->insert(['account_id' => $f['account'], 'user_id' => $f['owner']->id,
            'chart_account_id' => $f['charts']['asset']]);
        DB::table('operational_reconciliation_charts')->insert($base + ['chart_account_id' => $f['charts']['asset'],
            'type_snapshot' => 'asset', 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1]);
        DB::table('operational_reconciliation_accounts')->insert($base + ['account_id' => $f['account'],
            'chart_account_id' => $f['charts']['asset'], 'stored_balance_snapshot' => '0.00', 'currency_snapshot' => config('accounting.currency')]);
        $f['digest'] = $this->digest($f);
        $this->complete($f);

        return $f;
    }

    private function assertInvalidationOnly(array $before, array $f, int $chartId): void
    {
        $after = $this->allFixtureRows($f['owner']->id);
        $gate = $after['operational_accounting_integrations'][0];
        $this->assertSame('reconciling', $gate['state']);
        $this->assertNull($gate['reconciliation_reference']);
        $this->assertNull($gate['reconciled_at']);
        foreach (['historical_transaction_strategy', 'accounting_currency', 'transaction_high_water_id', 'scope_reviewed_at', 'activated_at'] as $field) {
            $this->assertSame($before['operational_accounting_integrations'][0][$field], $gate[$field]);
        }
        foreach ($before as $table => $rows) {
            if (! in_array($table, ['chart_of_accounts', 'operational_accounting_integrations'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
        $this->assertSame(array_values(array_filter($before['chart_of_accounts'], fn ($r) => $r['id'] !== $chartId)),
            array_values(array_filter($after['chart_of_accounts'], fn ($r) => $r['id'] !== $chartId)));
    }

    public static function states(): array
    {
        return [['reconciling'], ['ready']];
    }

    #[DataProvider('states')]
    public function test_selected_active_change_invalidates_only_binding(string $state): void
    {
        $f = $this->boundFixture();
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $this->assertFalse($this->updateChart($f, $f['charts']['asset'], ['active' => false])->is_active);
        $this->assertInvalidationOnly($before, $f, $f['charts']['asset']);
    }

    public function test_unused_selected_type_change_invalidates_but_referenced_code_and_type_stay_frozen(): void
    {
        $f = $this->boundFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        foreach ([['code' => 'NEWCODE'], ['type' => 'liability']] as $change) {
            try {
                $this->updateChart($f, $f['charts']['asset'], $change);
                $this->fail('Referenced chart changed.');
            } catch (AccountingConflict $e) {
                $this->assertStringContainsString('Referenced', $e->getMessage());
            }
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
        $this->assertSame('expense', $this->updateChart($f, $f['charts']['revenue'], ['type' => 'expense'])->type);
        $this->assertInvalidationOnly($before, $f, $f['charts']['revenue']);
    }

    public function test_display_hierarchy_and_semantic_noops_preserve_completed_binding(): void
    {
        $f = $this->boundFixture();
        $parent = $this->extraChart($f, 'PARENT');
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $before = $this->allFixtureRows($f['owner']->id);
        $this->updateChart($f, $f['charts']['asset']);
        (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'non_cash');
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->updateChart($f, $f['charts']['asset'], ['name' => 'Renamed', 'parent' => $parent]);
        $this->updateChart($f, $f['charts']['revenue'], ['code' => 'UNUSED_REVENUE']);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        $this->assertSame($before['operational_reconciliation_reviews'], $this->allFixtureRows($f['owner']->id)['operational_reconciliation_reviews']);
    }

    public function test_unrelated_and_journal_counterpart_active_changes_do_not_invalidate(): void
    {
        $f = $this->boundFixture();
        $unrelated = $this->extraChart($f);
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->updateChart($f, $unrelated, ['active' => false]);
        // Historical counterpart activity is not a selected-target prerequisite.
        $this->updateChart($f, $f['charts']['equity'], ['active' => false]);
        (new ReviewCashAccountRole)->execute($f['owner'], $unrelated, 'cash');
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    #[DataProvider('states')]
    public function test_permitted_selected_role_change_invalidates(string $state): void
    {
        $f = $this->zeroParticipant();
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $this->assertSame('cash', (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'cash')->cash_role);
        $this->assertInvalidationOnly($before, $f, $f['charts']['asset']);
    }

    public function test_assignment_to_stale_unreviewed_selected_target_invalidates(): void
    {
        $f = $this->zeroParticipant();
        // Deliberately stale live row; never weaken completion to accept NULL roles.
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['cash_role' => null]);
        $before = $this->allFixtureRows($f['owner']->id);
        (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'non_cash');
        $this->assertInvalidationOnly($before, $f, $f['charts']['asset']);
    }

    private function counterpartFixture(): array
    {
        $f = $this->fixture();
        $counterpart = $this->extraChart($f, 'COUNTERPART');
        $journal = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
            'entry_date' => '2026-10-06', 'currency' => config('accounting.currency')]);
        $line = DB::table('journal_lines')->insertGetId(['user_id' => $f['owner']->id, 'journal_entry_id' => $journal,
            'chart_account_id' => $f['charts']['asset'], 'line_number' => 1, 'debit' => '10.00', 'credit' => '0.00']);
        DB::table('journal_lines')->insert(['user_id' => $f['owner']->id, 'journal_entry_id' => $journal,
            'chart_account_id' => $counterpart, 'line_number' => 2, 'debit' => '0.00', 'credit' => '10.00']);
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => '2026-10-10 09:00:00.000000']);
        DB::table('accounts')->where('id', $f['account'])->update(['balance' => '110.00']);
        DB::table('operational_reconciliation_accounts')->where('review_id', $f['review'])->update(['stored_balance_snapshot' => '110.00']);
        DB::table('operational_reconciliation_journals')->insert(['review_id' => $f['review'], 'user_id' => $f['owner']->id, 'journal_entry_id' => $journal]);
        DB::table('operational_reconciliation_attributions')->insert(['review_id' => $f['review'], 'user_id' => $f['owner']->id,
            'journal_entry_id' => $journal, 'journal_line_id' => $line, 'target_kind' => 'account', 'account_id' => $f['account'],
            'purpose' => 'existing_history', 'amount' => '10.00']);
        $f['counterpart'] = $counterpart;
        $f['digest'] = $this->digest($f);
        $this->complete($f);

        return $f;
    }

    #[DataProvider('states')]
    public function test_unselected_reviewed_journal_counterpart_role_assignment_invalidates(string $state): void
    {
        $f = $this->counterpartFixture();
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $this->assertSame('cash', (new ReviewCashAccountRole)->execute($f['owner'], $f['counterpart'], 'cash')->cash_role);
        $this->assertInvalidationOnly($before, $f, $f['counterpart']);
        $this->assertDatabaseMissing('operational_account_mappings', ['user_id' => $f['owner']->id, 'chart_account_id' => $f['counterpart']]);
        $this->assertDatabaseMissing('operational_reconciliation_charts', ['review_id' => $f['review'], 'chart_account_id' => $f['counterpart']]);
    }

    public function test_old_review_only_counterpart_does_not_invalidate_current_binding(): void
    {
        $f = $this->counterpartFixture();
        $oldReview = $f['review'];
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['reconciliation_reference' => null, 'reconciled_at' => null]);
        foreach (['account', 'category'] as $kind) {
            DB::table('operational_accounting_'.$kind.'_scope')->where('user_id', $f['owner']->id)->delete();
        }
        $header = (array) DB::table('operational_reconciliation_reviews')->where('id', $oldReview)->firstOrFail();
        unset($header['id']);
        $header['reference'] = (string) Str::uuid();
        $header['reviewed_by'] = null;
        $header['completed_at'] = null;
        $header['supersedes_review_id'] = $oldReview;
        $f['review'] = DB::table('operational_reconciliation_reviews')->insertGetId($header);
        $f['reference'] = $header['reference'];
        $f['digest'] = $this->digest($f);
        $this->complete($f);
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        (new ReviewCashAccountRole)->execute($f['owner'], $f['counterpart'], 'cash');
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    public function test_posted_use_role_freeze_rejects_without_clearing_evidence(): void
    {
        $f = $this->boundFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'cash');
            $this->fail('Posted role changed.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::CashRoleFrozen, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public static function actions(): array
    {
        return [['update'], ['role']];
    }

    #[DataProvider('actions')]
    public function test_active_and_foreign_mutations_reject_without_writes(string $action): void
    {
        $f = $this->zeroParticipant();
        $foreign = $this->boundFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        $other = $this->allFixtureRows($foreign['owner']->id);
        try {
            if ($action === 'update') {
                (new UpdateChartAccount)->execute($f['owner'], $foreign['charts']['asset'], 'ASSET', 'Foreign', 'asset', false);
            } else {
                (new ReviewCashAccountRole)->execute($f['owner'], $foreign['charts']['asset'], 'cash');
            }
            $this->fail('Foreign chart mutated.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        }
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => now()]);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            if ($action === 'update') {
                $this->updateChart($f, $f['charts']['asset'], ['active' => false]);
            } else {
                (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'cash');
            }
            $this->fail('Active evidence invalidated.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationState, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->assertSame($other, $this->allFixtureRows($foreign['owner']->id));
    }

    public static function failures(): array
    {
        return [['update', 'chart_of_accounts'], ['update', 'operational_accounting_integrations'],
            ['role', 'chart_of_accounts'], ['role', 'operational_accounting_integrations']];
    }

    #[DataProvider('failures')]
    public function test_failure_after_each_write_rolls_back_mutation_and_invalidation(string $action, string $table): void
    {
        $f = $this->zeroParticipant();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $before = $this->allFixtureRows($f['owner']->id);
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($table): void {
            if (str_starts_with($q->sql, 'update `'.$table.'`')) {
                throw new RuntimeException('Injected '.$table);
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            if ($action === 'update') {
                $this->updateChart($f, $f['charts']['asset'], ['active' => false]);
            } else {
                (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'cash');
            }
            $this->fail('Injected failure committed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected '.$table, $e->getMessage());
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    #[DataProvider('actions')]
    public function test_absent_gate_is_not_created_and_unbound_gate_is_unchanged(string $action): void
    {
        $f = $this->fixture();
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        if ($action === 'update') {
            $this->updateChart($f, $f['charts']['revenue'], ['active' => false]);
        } else {
            DB::table('chart_of_accounts')->where('id', $f['charts']['revenue'])->update(['cash_role' => null]);
            (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['revenue'], 'non_cash');
        }
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->delete();
        if ($action === 'update') {
            $this->updateChart($f, $f['charts']['revenue'], ['active' => true]);
        } else {
            (new ReviewCashAccountRole)->execute($f['owner'], $this->extraChart($f), 'cash');
        }
        $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $f['owner']->id]);
    }

    public function test_mutation_first_waiting_ready_rejects(): void
    {
        $f = $this->boundFixture();
        DB::beginTransaction();
        try {
            $this->updateChart($f, $f['charts']['asset'], ['active' => false]);
            $job = $this->startWorker($f, 'ready');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::commit();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame('ReconciliationState', $result['reason']);
    }

    public function test_ready_first_chart_mutation_waits_then_demotes(): void
    {
        $f = $this->boundFixture();
        $f['chart_target'] = $f['charts']['asset'];
        $job = null;
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($f, &$job): void {
            if (str_starts_with($q->sql, 'update `operational_accounting_integrations`') && $job === null) {
                $job = $this->startWorker($f, 'deactivate-chart');
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

    public function test_different_owner_lock_does_not_block_chart_mutation(): void
    {
        $f = $this->boundFixture();
        $other = $this->boundFixture();
        $f['chart_target'] = $f['charts']['asset'];
        $before = $this->allFixtureRows($other['owner']->id);
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $other['owner']->id)->lockForUpdate()->first();
            $job = $this->startWorker($f, 'deactivate-chart');
            $result = $this->waitEvent($job, 'result');
            $this->assertTrue($result['ok'], $result['message'] ?? '');
        } finally {
            DB::commit();
        }
        $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
    }

    public function test_scope_relevance_is_current_under_repeatable_read(): void
    {
        $f = $this->boundFixture('balance_only', true);
        DB::table('operational_account_mappings')->insert(['user_id' => $f['owner']->id,
            'account_id' => $f['account'], 'chart_account_id' => $f['charts']['asset']]);
        $this->configureProbeConnection('chart_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($f, &$attempts): void {
            if (! str_contains($sql, '`users`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $attempts++;
            $this->assertFalse(DB::table('operational_accounting_account_scope')->where('account_id', $f['account'])->exists());
            DB::connection('chart_probe')->transaction(function () use ($f): void {
                DB::connection('chart_probe')->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                DB::connection('chart_probe')->table('operational_accounting_account_scope')
                    ->insert(['user_id' => $f['owner']->id, 'account_id' => $f['account']]);
            });
            $this->assertTrue(DB::connection('chart_probe')->table('operational_accounting_account_scope')->where('account_id', $f['account'])->exists());
            $this->assertFalse(DB::table('operational_accounting_account_scope')->where('account_id', $f['account'])->exists(), 'The consistent snapshot must remain stale.');
        });
        try {
            $this->updateChart($f, $f['charts']['asset'], ['active' => false]);
        } finally {
            $callbacks->setValue($connection, $original);
        }
        $this->assertSame(1, $attempts, 'Retries must not mask current-read authority.');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    #[DataProvider('actions')]
    public function test_reference_freezes_use_current_posted_history_not_old_snapshot(string $action): void
    {
        $f = $this->zeroParticipant();
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $chart = DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->first();
        $this->configureProbeConnection('chart_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($f, &$attempts): void {
            if (! str_contains($sql, '`users`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $attempts++;
            $this->assertSame(0, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
            DB::connection('chart_probe')->transaction(function () use ($f): void {
                $probe = DB::connection('chart_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $journal = $probe->table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
                    'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'), 'status' => 'draft']);
                $probe->table('journal_lines')->insert([
                    ['user_id' => $f['owner']->id, 'journal_entry_id' => $journal, 'chart_account_id' => $f['charts']['asset'], 'line_number' => 1, 'debit' => '1.00', 'credit' => '0.00'],
                    ['user_id' => $f['owner']->id, 'journal_entry_id' => $journal, 'chart_account_id' => $f['charts']['equity'], 'line_number' => 2, 'debit' => '0.00', 'credit' => '1.00'],
                ]);
                $probe->table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => '2026-10-10 09:00:00.000000']);
            });
            $this->assertSame(1, DB::connection('chart_probe')->table('journal_entries')->where('user_id', $f['owner']->id)->count());
            $this->assertSame(0, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
        });
        try {
            if ($action === 'update') {
                $this->updateChart($f, $f['charts']['asset'], ['code' => 'CHANGED']);
            } else {
                (new ReviewCashAccountRole)->execute($f['owner'], $f['charts']['asset'], 'cash');
            }
            $this->fail('Current posted use was hidden by the old snapshot.');
        } catch (AccountingConflict $e) {
            if ($action === 'role') {
                $this->assertSame(AccountingConflictReason::CashRoleFrozen, $e->reason);
            } else {
                $this->assertSame('Referenced chart account code and type cannot change.', $e->getMessage());
            }
        } finally {
            $callbacks->setValue($connection, $original);
        }
        $this->assertSame(1, $attempts);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        $this->assertEquals($chart, DB::table('chart_of_accounts')->where('id', $chart->id)->first());
    }

    public function test_update_locks_owner_guards_integration_and_participation_before_hierarchy(): void
    {
        $f = $this->boundFixture();
        $queries = [];
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            if (str_contains($q->sql, 'for update') || str_contains($q->sql, 'lock in share mode')) {
                $queries[] = $q->sql;
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->updateChart($f, $f['charts']['asset'], ['active' => false]);
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $tables = ['users', 'fiscal_year_closes', 'operational_accounting_integrations',
            'operational_accounting_account_scope', 'operational_accounting_category_scope',
            'operational_account_mappings', 'operational_category_mappings', 'chart_of_accounts'];
        $this->assertCount(count($tables), $queries);
        foreach ($tables as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $queries[$i]);
        }
        $this->assertStringContainsString('order by `id` asc', $queries[7]);
    }

    public function test_lock_stages_keep_integration_before_charts_and_membership_before_review(): void
    {
        $f = $this->counterpartFixture();
        $queries = [];
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            if (str_contains($q->sql, 'for update') || str_contains($q->sql, 'lock in share mode')) {
                $queries[] = $q->sql;
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            (new ReviewCashAccountRole)->execute($f['owner'], $f['counterpart'], 'cash');
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $tables = ['users', 'operational_accounting_integrations', 'operational_accounting_account_scope',
            'operational_accounting_category_scope', 'operational_account_mappings', 'operational_category_mappings',
            'chart_of_accounts', 'journal_entries', 'journal_lines', 'operational_reconciliation_journals',
            'operational_reconciliation_reviews'];
        $this->assertCount(count($tables), $queries);
        foreach ($tables as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $queries[$i]);
            $this->assertStringNotContainsString('nowait', $queries[$i]);
        }
        $this->assertStringContainsString('order by `id` asc', $queries[7]);
        $this->assertStringContainsString('order by `id` asc', $queries[8]);
    }
}
