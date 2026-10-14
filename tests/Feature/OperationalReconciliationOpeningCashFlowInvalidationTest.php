<?php

namespace Tests\Feature;

use App\Accounting\Actions\CompleteHistoricalCashFlowJournal;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\HistoricalCashFlowCompletionOutcome;
use App\Models\User;
use App\OperationalAccounting\Actions\MarkOperationalAccountingReady;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OperationalReconciliationOpeningCashFlowInvalidationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function openingDraft(array $f, ?int $chart = null): int
    {
        $id = DB::table('opening_balance_batches')->insertGetId(['user_id' => $f['owner']->id,
            'opening_date' => '2026-10-12', 'currency' => config('accounting.currency')]);
        DB::table('opening_balance_lines')->insert([
            ['user_id' => $f['owner']->id, 'batch_id' => $id, 'chart_account_id' => $chart ?? $f['charts']['asset'], 'debit' => '0.01', 'credit' => '0.00'],
            ['user_id' => $f['owner']->id, 'batch_id' => $id, 'chart_account_id' => $f['charts']['equity'], 'debit' => '0.00', 'credit' => '0.01'],
        ]);

        return $id;
    }

    private function extraChart(array $f, string $role = 'non_cash'): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id,
            'code' => 'EXTRA', 'name' => 'Outside scope', 'type' => 'asset', 'cash_role' => $role, 'is_active' => 1]);
    }

    private function cashAllocations(array $f, int $journal): array
    {
        $lines = DB::table('journal_lines')->where('journal_entry_id', $journal)->orderBy('line_number')->get();

        return [['debit_line_id' => $lines[0]->id, 'credit_line_id' => $lines[1]->id,
            'amount' => $lines[0]->debit, 'category' => 'financing']];
    }

    private function staleCashFixture(string $state = 'reconciling'): array
    {
        $f = $this->boundFixture();
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        // Explicit external staleness, AFTER genuine completion/READY validation.
        // Never approve incomplete ordinary cash history through those actions.
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['cash_role' => 'cash']);
        if ($state === 'active') {
            // Establish this deliberately stale fixture before ACTIVE freezes
            // participating chart semantics; the action still runs while ACTIVE.
            DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
                ->update(['state' => 'active', 'activated_at' => now()]);
        }
        $f['history_journal'] = $f['journal'];
        $f['history_allocations'] = $this->cashAllocations($f, $f['journal']);

        return $f;
    }

    private function freshCashFixture(): array
    {
        $f = $this->fixture();
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['cash_role' => 'cash']);
        DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])
            ->where('chart_account_id', $f['charts']['asset'])->update(['cash_role_snapshot' => 'cash']);
        $f['history_journal'] = $f['journal'];
        $f['history_allocations'] = $this->cashAllocations($f, $f['journal']);
        DB::table('journal_line_allocations')->insert($f['history_allocations'][0] + ['user_id' => $f['owner']->id, 'journal_entry_id' => $f['journal']]);
        DB::table('cash_flow_journal_completions')->insert(['user_id' => $f['owner']->id,
            'journal_entry_id' => $f['journal'], 'completed_at' => now()->format('Y-m-d H:i:s.u')]);
        $f['digest'] = $this->digest($f);
        $this->complete($f);

        return $f;
    }

    private function unrelatedCashJournal(array $f): array
    {
        $chart = $this->extraChart($f, 'cash');
        $id = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
            'entry_date' => '2026-10-06', 'currency' => config('accounting.currency')]);
        DB::table('journal_lines')->insert([
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $id, 'line_number' => 1,
                'chart_account_id' => $chart, 'debit' => '1.00', 'credit' => '0.00'],
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $id, 'line_number' => 2,
                'chart_account_id' => $f['charts']['equity'], 'debit' => '0.00', 'credit' => '1.00'],
        ]);
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
        $f['history_journal'] = $id;
        $f['history_allocations'] = $this->cashAllocations($f, $id);

        return $f;
    }

    private function performC2Mutation(array $f, string $kind): void
    {
        if ($kind === 'opening') {
            $this->assertTrue((new PostOpeningBalanceBatch)->execute($f['owner'], $f['opening_batch'])->isPosted());
        } else {
            $this->assertSame(HistoricalCashFlowCompletionOutcome::Completed,
                (new CompleteHistoricalCashFlowJournal)->execute($f['owner'], $f['history_journal'], $f['history_allocations']));
        }
    }

    private function assertBindingCleared(array $f, array $before): void
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
            if (str_starts_with($table, 'operational_reconciliation_') || in_array($table,
                ['accounts', 'categories', 'transactions', 'chart_of_accounts', 'operational_account_mappings',
                    'operational_category_mappings', 'operational_accounting_account_scope', 'operational_accounting_category_scope'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
    }

    public static function kinds(): array
    {
        return [['opening'], ['cashflow']];
    }

    public static function states(): array
    {
        return [['opening', 'reconciling'], ['opening', 'ready'], ['cashflow', 'reconciling'], ['cashflow', 'ready']];
    }

    #[DataProvider('states')]
    public function test_relevant_mutation_clears_pair_and_preserves_completed_review(string $kind, string $state): void
    {
        $f = $kind === 'opening' ? $this->boundFixture() : $this->staleCashFixture($state);
        if ($kind === 'opening') {
            if ($state === 'ready') {
                (new MarkOperationalAccountingReady)->execute($f['owner']);
            }
            $f['opening_batch'] = $this->openingDraft($f);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $this->performC2Mutation($f, $kind);
        $this->assertBindingCleared($f, $before);
    }

    #[DataProvider('kinds')]
    public function test_unrelated_mutation_and_equity_counterpart_do_not_infer_participation(string $kind): void
    {
        $f = $this->boundFixture();
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f, $this->extraChart($f));
        } else {
            $f = $this->unrelatedCashJournal($f);
        }
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->performC2Mutation($f, $kind);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        $this->assertDatabaseMissing('operational_reconciliation_charts', ['review_id' => $f['review'], 'chart_account_id' => $f['charts']['equity']]);
    }

    public function test_opening_retry_preserves_binding_after_period_close_and_chart_and_config_changes(): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f, $this->extraChart($f));
        $posted = (new PostOpeningBalanceBatch)->execute($f['owner'], $batch);
        DB::table('accounting_periods')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-12',
            'end_date' => '2026-10-12', 'status' => 'closed', 'first_closed_at' => now()]);
        DB::table('chart_of_accounts')->where('user_id', $f['owner']->id)->where('code', 'EXTRA')->update(['is_active' => 0]);
        config(['accounting.currency' => config('accounting.currency') === 'USD' ? 'SAR' : 'USD']);
        $before = $this->allFixtureRows($f['owner']->id);
        $retry = (new PostOpeningBalanceBatch)->execute($f['owner'], $batch);
        $this->assertSame($posted->journal_entry_id, $retry->journal_entry_id);
        $this->assertSame($posted->posted_at->format('Y-m-d H:i:s.u'), $retry->posted_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    #[DataProvider('kinds')]
    public function test_active_relevant_rejects_atomically_but_unrelated_operation_is_allowed(string $kind): void
    {
        $f = $kind === 'cashflow' ? $this->staleCashFixture('active') : $this->boundFixture();
        if ($kind === 'opening') {
            DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => now()]);
            $f['opening_batch'] = $this->openingDraft($f);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->performC2Mutation($f, $kind);
            $this->fail('Active binding changed.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationState, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f, $this->extraChart($f));
        } else {
            $f = $this->unrelatedCashJournal($f);
        }
        $this->performC2Mutation($f, $kind);
        $this->assertEquals($before['operational_accounting_integrations'], $this->allFixtureRows($f['owner']->id)['operational_accounting_integrations']);
    }

    public static function failures(): array
    {
        return [['opening', 'line'], ['opening', 'invalidation'], ['opening', 'batch'],
            ['cashflow', 'allocation'], ['cashflow', 'invalidation'], ['cashflow', 'completion']];
    }

    #[DataProvider('failures')]
    public function test_failures_restore_financial_state_and_binding(string $kind, string $stage): void
    {
        $f = $kind === 'opening' ? $this->boundFixture() : $this->staleCashFixture('ready');
        if ($kind === 'opening') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
            $f['opening_batch'] = $this->openingDraft($f);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $prefix = match ($stage) {
            'line' => 'insert into `journal_lines`', 'invalidation' => 'update `operational_accounting_integrations`',
            'batch' => 'update `opening_balance_batches`', 'allocation' => 'insert into `journal_line_allocations`',
            'completion' => 'insert into `cash_flow_journal_completions`',
        };
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($prefix): void {
            if (str_starts_with($q->sql, $prefix)) {
                throw new RuntimeException('Injected C2 failure');
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->performC2Mutation($f, $kind);
            $this->fail('Failed mutation committed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected C2 failure', $e->getMessage());
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        // A real retry must create only one opening journal after the rollback.
        $this->performC2Mutation($f, $kind);
        $this->assertBindingCleared($f, $before);
        if ($kind === 'opening') {
            $this->assertSame(count($before['journal_entries']) + 1, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
        } else {
            $this->assertSame(1, DB::table('cash_flow_journal_completions')->where('journal_entry_id', $f['history_journal'])->count());
        }
    }

    public function test_historical_already_completed_rejects_and_noncash_noop_preserves_binding(): void
    {
        $f = $this->freshCashFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->performC2Mutation($f, 'cashflow');
            $this->fail('Already completed accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::CashFlowAlreadyCompleted, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $other = $this->boundFixture();
        $before = $this->allFixtureRows($other['owner']->id);
        $this->assertSame(HistoricalCashFlowCompletionOutcome::NoCashLines,
            (new CompleteHistoricalCashFlowJournal)->execute($other['owner'], $other['journal'], []));
        $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
    }

    public function test_historical_completion_remains_allowed_in_closed_period_and_fiscal_range(): void
    {
        $f = $this->staleCashFixture();
        DB::table('accounting_periods')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-01',
            'end_date' => '2026-10-31', 'status' => 'closed', 'first_closed_at' => now()]);
        DB::table('fiscal_year_closes')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $f['charts']['equity'],
            'journal_entry_id' => null, 'closed_at' => now()->format('Y-m-d H:i:s.u')]);
        $before = $this->allFixtureRows($f['owner']->id);
        $this->performC2Mutation($f, 'cashflow');
        $this->assertBindingCleared($f, $before);
    }

    #[DataProvider('kinds')]
    public function test_absent_gate_and_owner_identity_and_foreign_sources(string $kind): void
    {
        $f = $kind === 'cashflow' ? $this->staleCashFixture() : $this->boundFixture();
        $other = $this->boundFixture();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->delete();
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f);
            $foreign = $this->openingDraft($other);
        }
        $this->performC2Mutation($f, $kind);
        $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $f['owner']->id]);
        $before = $this->allFixtureRows($other['owner']->id);
        foreach ([$f['owner'], new User] as $actor) {
            try {
                if ($kind === 'opening') {
                    (new PostOpeningBalanceBatch)->execute($actor, $foreign);
                } else {
                    (new CompleteHistoricalCashFlowJournal)->execute($actor, $other['journal'], []);
                }
                $this->fail('Foreign or unsaved owner accepted.');
            } catch (ModelNotFoundException) {
                $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
            }
        }
    }

    #[DataProvider('kinds')]
    public function test_committed_mutation_first_waiting_ready_rejects(string $kind): void
    {
        $f = $kind === 'opening' ? $this->boundFixture() : $this->staleCashFixture();
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f);
        }
        DB::beginTransaction();
        try {
            $this->performC2Mutation($f, $kind);
            $job = $this->startWorker($f, 'ready');
            $this->assertWorkerWaiting($job);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame('ReconciliationState', $result['reason']);
    }

    public function test_rolled_back_opening_allows_waiting_ready(): void
    {
        $f = $this->boundFixture();
        $f['opening_batch'] = $this->openingDraft($f);
        DB::beginTransaction();
        try {
            $this->performC2Mutation($f, 'opening');
            $job = $this->startWorker($f, 'ready');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::rollBack();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('opening_balance_batches', ['id' => $f['opening_batch'], 'status' => 'draft', 'journal_entry_id' => null]);
    }

    public static function readyRaces(): array
    {
        return [['opening'], ['fresh cashflow']];
    }

    #[DataProvider('readyRaces')]
    public function test_authoritative_ready_first_serializes_mutation_with_correct_outcome(string $kind): void
    {
        $f = $kind === 'opening' ? $this->boundFixture() : $this->freshCashFixture();
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f);
        }
        $job = null;
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($f, $kind, &$job): void {
            if (str_starts_with($q->sql, 'update `operational_accounting_integrations`') && $job === null) {
                $job = $this->startWorker($f, $kind === 'opening' ? 'post-opening' : 'historical-cashflow');
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
        if ($kind === 'opening') {
            $this->assertTrue($result['ok'], $result['message'] ?? '');
            $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
                'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
        } else {
            // Fresh READY requires this cash journal's immutable completion.
            $this->assertFalse($result['ok']);
            $this->assertSame('CashFlowAlreadyCompleted', $result['reason']);
            $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
                'state' => 'ready', 'reconciliation_reference' => $f['reference']]);
        }
    }

    public function test_defensively_stale_ready_cashflow_waits_then_demotes(): void
    {
        $f = $this->staleCashFixture('ready');
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
            $job = $this->startWorker($f, 'historical-cashflow');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::rollBack();
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    #[DataProvider('kinds')]
    public function test_different_owner_lock_does_not_block_c2(string $kind): void
    {
        $f = $kind === 'opening' ? $this->boundFixture() : $this->staleCashFixture();
        $other = $this->boundFixture();
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f);
        }
        $before = $this->allFixtureRows($other['owner']->id);
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $other['owner']->id)->lockForUpdate()->first();
            $job = $this->startWorker($f, $kind === 'opening' ? 'post-opening' : 'historical-cashflow');
            $result = $this->waitEvent($job, 'result');
            $this->assertTrue($result['ok'], $result['message'] ?? '');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
    }

    #[DataProvider('kinds')]
    public function test_exact_source_before_evidence_lock_order(string $kind): void
    {
        $f = $kind === 'opening' ? $this->boundFixture() : $this->staleCashFixture();
        if ($kind === 'opening') {
            $f['opening_batch'] = $this->openingDraft($f);
        }
        $queries = [];
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->performC2Mutation($f, $kind);
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $positions = [];
        foreach ($queries as $i => $query) {
            if (str_contains($query, 'for update') || str_contains($query, 'lock in share mode')) {
                preg_match('/from `([^`]+)`/', $query, $match);
                $positions[$match[1]] ??= $i;
            }
        }
        $tables = ['users'];
        if ($kind === 'opening') {
            $tables[] = 'accounting_periods';
        }
        array_push($tables, 'fiscal_year_closes', 'operational_accounting_integrations', 'operational_accounting_account_scope',
            'operational_accounting_category_scope', 'operational_account_mappings', 'operational_category_mappings', 'chart_of_accounts');
        if ($kind === 'opening') {
            array_push($tables, 'opening_balance_batches', 'opening_balance_lines');
        } else {
            array_push($tables, 'journal_entries', 'journal_lines', 'journal_line_allocations', 'cash_flow_journal_completions', 'opening_balance_batches');
            $this->assertArrayNotHasKey('accounting_periods', $positions, 'Classification must not require an open period.');
        }
        array_push($tables, 'operational_reconciliation_charts', 'operational_reconciliation_journals', 'operational_reconciliation_reviews');
        foreach ($tables as $i => $table) {
            $this->assertArrayHasKey($table, $positions);
            if ($i > 0) {
                $this->assertLessThan($positions[$table], $positions[$tables[$i - 1]]);
            }
        }
        $this->assertStringContainsString('order by `id` asc', $queries[$positions['chart_of_accounts']]);
        if ($kind === 'opening') {
            $header = array_find_key($queries, fn ($q) => str_starts_with($q, 'insert into `journal_entries`'));
            $line = array_find_key($queries, fn ($q) => str_starts_with($q, 'insert into `journal_lines`'));
            $this->assertNotNull($header);
            $this->assertNotNull($line);
            $this->assertLessThan($header, $positions['opening_balance_lines']);
            $this->assertLessThan($line, $header);
            $this->assertLessThan($positions['operational_reconciliation_charts'], $line);
        }
    }

    /** Only callback/connection lifecycle mechanics; assertions stay in tests. */
    private function beforeOwnerProbe(callable $coordination, callable $operation): int
    {
        $this->configureProbeConnection('c2_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($coordination, &$attempts): void {
            if (str_contains($sql, '`users`') && str_contains($sql, 'for update')) {
                $coordination(++$attempts);
            }
        });
        try {
            $operation();
        } finally {
            $callbacks->setValue($connection, $original);
        }

        return $attempts;
    }

    public static function openingChanges(): array
    {
        return [['chart'], ['date'], ['amount']];
    }

    #[DataProvider('openingChanges')]
    public function test_current_opening_sources_cannot_be_hidden_by_repeatable_read(string $change): void
    {
        $f = $this->boundFixture();
        $outside = $this->extraChart($f);
        $batch = $this->openingDraft($f, $outside);
        $line = DB::table('opening_balance_lines')->where('batch_id', $batch)->orderBy('id')->value('id');
        $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $batch, $line, $outside, $change): void {
            if ($attempt !== 1) {
                return;
            }
            $old = DB::table('opening_balance_batches')->where('id', $batch)->first();
            $this->assertSame('2026-10-12', $old->opening_date);
            $this->assertSame($outside, DB::table('opening_balance_lines')->where('id', $line)->value('chart_account_id'));
            DB::connection('c2_probe')->transaction(function () use ($f, $batch, $line, $change): void {
                $probe = DB::connection('c2_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                if ($change === 'chart') {
                    $probe->table('opening_balance_lines')->where('id', $line)->update(['chart_account_id' => $f['charts']['asset']]);
                } elseif ($change === 'date') {
                    $probe->table('opening_balance_batches')->where('id', $batch)->update(['opening_date' => '2026-10-13']);
                } else {
                    $probe->table('opening_balance_lines')->where('batch_id', $batch)->where('debit', '>', 0)->update(['debit' => '0.02']);
                    $probe->table('opening_balance_lines')->where('batch_id', $batch)->where('credit', '>', 0)->update(['credit' => '0.02']);
                }
            });
            $this->assertSame('2026-10-12', DB::table('opening_balance_batches')->where('id', $batch)->value('opening_date'));
            $this->assertSame($outside, DB::table('opening_balance_lines')->where('id', $line)->value('chart_account_id'));
            $this->assertSame('0.01', DB::table('opening_balance_lines')->where('id', $line)->value('debit'));
            $probe = DB::connection('c2_probe');
            $this->assertSame($change === 'date' ? '2026-10-13' : '2026-10-12', $probe->table('opening_balance_batches')->where('id', $batch)->value('opening_date'));
            $this->assertSame($change === 'chart' ? $f['charts']['asset'] : $outside, $probe->table('opening_balance_lines')->where('id', $line)->value('chart_account_id'));
        }, fn () => (new PostOpeningBalanceBatch)->execute($f['owner'], $batch));
        $this->assertSame($change === 'amount' ? 1 : 2, $attempts);
        $posted = DB::table('opening_balance_batches')->where('id', $batch)->first();
        $this->assertSame('posted', $posted->status);
        $this->assertSame($change === 'date' ? '2026-10-13' : '2026-10-12', $posted->opening_date);
        $this->assertSame($change === 'amount' ? '0.02' : '0.01', DB::table('journal_lines')->where('journal_entry_id', $posted->journal_entry_id)->where('line_number', 1)->value('debit'));
        $this->assertSame(2, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count(), 'Rediscovery must not leave a partial journal.');
        $this->assertSame($change === 'chart' ? null : $f['reference'], DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
    }

    public function test_current_link_appearance_restarts_before_late_header_lock_and_does_not_duplicate(): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f);
        $originalDispatcher = DB::connection()->getEventDispatcher();
        $queries = [];
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $batch): void {
                if ($attempt !== 1) {
                    return;
                }
                $this->assertNull(DB::table('opening_balance_batches')->where('id', $batch)->value('journal_entry_id'));
                $default = DB::getDefaultConnection();
                try {
                    DB::setDefaultConnection('c2_probe');
                    $probeOwner = User::on('c2_probe')->findOrFail($f['owner']->id);
                    $posted = (new PostOpeningBalanceBatch)->execute($probeOwner, $batch);
                    $this->assertNotNull($posted->journal_entry_id);
                } finally {
                    DB::setDefaultConnection($default);
                }
                $this->assertNull(DB::table('opening_balance_batches')->where('id', $batch)->value('journal_entry_id'));
                $this->assertNotNull(DB::connection('c2_probe')->table('opening_balance_batches')->where('id', $batch)->value('journal_entry_id'));
            }, fn () => (new PostOpeningBalanceBatch)->execute($f['owner'], $batch));
        } finally {
            DB::connection()->setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(2, $attempts);
        $ownerPositions = array_keys(array_filter($queries, fn ($q) => str_contains($q, 'from `users`') && str_contains($q, 'for update')));
        $this->assertCount(2, $ownerPositions);
        $firstAttempt = array_slice($queries, $ownerPositions[0], $ownerPositions[1] - $ownerPositions[0]);
        foreach ($firstAttempt as $query) {
            $this->assertFalse(str_contains($query, 'from `journal_entries`') && str_contains($query, 'for update'), 'No backwards header lock in the first attempt.');
        }
        $secondAttempt = array_slice($queries, $ownerPositions[1]);
        $header = array_find_key($secondAttempt, fn ($q) => str_contains($q, 'from `journal_entries`') && str_contains($q, 'for update'));
        $batchLock = array_find_key($secondAttempt, fn ($q) => str_contains($q, 'from `opening_balance_batches`') && str_contains($q, 'for update'));
        $this->assertNotNull($header);
        $this->assertNotNull($batchLock);
        $this->assertLessThan($batchLock, $header);
        $this->assertSame(2, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
    }

    private function attachSpecial(array $f, string $kind, string $connection): void
    {
        $db = DB::connection($connection);
        if ($kind === 'opening') {
            $batch = $db->table('opening_balance_batches')->insertGetId(['user_id' => $f['owner']->id,
                'opening_date' => '2026-10-06', 'currency' => config('accounting.currency')]);
            $db->table('opening_balance_batches')->where('id', $batch)->update(['status' => 'posted',
                'journal_entry_id' => $f['history_journal'], 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
        } elseif ($kind === 'fiscal') {
            $db->table('fiscal_year_closes')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-01',
                'end_date' => '2026-10-06', 'currency' => config('accounting.currency'),
                'retained_earnings_account_id' => $f['charts']['equity'], 'journal_entry_id' => $f['history_journal'],
                'closed_at' => now()->format('Y-m-d H:i:s.u')]);
        } else {
            $db->table('journal_entries')->where('id', $f['history_journal'])->update(['reversal_of_id' => $f['journal'],
                'status' => 'posted', 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
        }
    }

    private function draftReversalSource(array $f): array
    {
        $id = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
            'entry_date' => '2026-10-06', 'currency' => config('accounting.currency')]);
        DB::table('journal_lines')->insert([
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $id, 'line_number' => 1,
                'chart_account_id' => $f['charts']['asset'], 'debit' => '0.00', 'credit' => '100.00'],
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $id, 'line_number' => 2,
                'chart_account_id' => $f['charts']['equity'], 'debit' => '100.00', 'credit' => '0.00'],
        ]);
        $f['history_journal'] = $id;

        return $f;
    }

    public static function specials(): array
    {
        return [['opening'], ['fiscal'], ['reversal']];
    }

    #[DataProvider('specials')]
    public function test_special_history_rejects_without_clearing_binding(string $kind): void
    {
        $f = $this->staleCashFixture('ready');
        if ($kind === 'reversal') {
            $f = $this->draftReversalSource($f);
        }
        // Structural stale provenance only; do not weaken reconciliation review.
        $this->attachSpecial($f, $kind, DB::getDefaultConnection());
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->performC2Mutation($f, 'cashflow');
            $this->fail('Special journal accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame($kind === 'reversal' ? AccountingConflictReason::CashFlowReversalRequiresInheritance : AccountingConflictReason::CashFlowSpecialJournal, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    #[DataProvider('specials')]
    public function test_special_exclusions_use_current_reads_under_repeatable_read(string $kind): void
    {
        $f = $this->staleCashFixture();
        if ($kind === 'reversal') {
            $f = $this->draftReversalSource($f);
        }
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $kind): void {
            $this->assertSame(1, $attempt, 'Current special reads must not need a new snapshot.');
            if ($kind === 'reversal') {
                $this->assertNull(DB::table('journal_entries')->where('id', $f['history_journal'])->value('reversal_of_id'));
            } else {
                $table = $kind === 'opening' ? 'opening_balance_batches' : 'fiscal_year_closes';
                $this->assertFalse(DB::table($table)->where('journal_entry_id', $f['history_journal'])->exists());
            }
            DB::connection('c2_probe')->transaction(function () use ($f, $kind): void {
                DB::connection('c2_probe')->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $this->attachSpecial($f, $kind, 'c2_probe');
            });
            if ($kind === 'reversal') {
                $this->assertSame($f['journal'], DB::connection('c2_probe')->table('journal_entries')->where('id', $f['history_journal'])->value('reversal_of_id'));
                $this->assertNull(DB::table('journal_entries')->where('id', $f['history_journal'])->value('reversal_of_id'));
            } else {
                $this->assertTrue(DB::connection('c2_probe')->table($table)->where('journal_entry_id', $f['history_journal'])->exists());
                $this->assertFalse(DB::table($table)->where('journal_entry_id', $f['history_journal'])->exists());
            }
        }, function () use ($f, $kind): void {
            try {
                $this->performC2Mutation($f, 'cashflow');
                $this->fail('Current special provenance was hidden.');
            } catch (AccountingConflict $e) {
                $this->assertSame($kind === 'reversal' ? AccountingConflictReason::CashFlowReversalRequiresInheritance : AccountingConflictReason::CashFlowSpecialJournal, $e->reason);
            }
        });
        $this->assertSame(1, $attempts);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    public function test_current_membership_is_not_hidden_by_old_binding_snapshot(): void
    {
        $f = $this->staleCashFixture();
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['reconciliation_reference' => null, 'reconciled_at' => null]);
        $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $gate): void {
            $this->assertSame(1, $attempt);
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
            DB::connection('c2_probe')->transaction(function () use ($f, $gate): void {
                $probe = DB::connection('c2_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
                    ->update(['reconciliation_reference' => $gate->reconciliation_reference, 'reconciled_at' => $gate->reconciled_at]);
            });
            $this->assertSame($gate->reconciliation_reference, DB::connection('c2_probe')->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
        }, fn () => $this->performC2Mutation($f, 'cashflow'));
        $this->assertSame(1, $attempts);
        $this->assertDatabaseHas('cash_flow_journal_completions', ['journal_entry_id' => $f['history_journal']]);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }
}
