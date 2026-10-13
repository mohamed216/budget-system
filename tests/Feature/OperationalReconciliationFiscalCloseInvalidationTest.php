<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseFiscalYear;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
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

class OperationalReconciliationFiscalCloseInvalidationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function fiscalArguments(array $f): array
    {
        return $f + ['fiscal_start' => '2026-10-01', 'fiscal_end' => '2026-10-31', 'retained_account' => $f['charts']['equity']];
    }

    private function closeYear(array $f): \App\Models\FiscalYearClose
    {
        $f = $this->fiscalArguments($f);

        return (new CloseFiscalYear)->execute($f['owner'], $f['fiscal_start'], $f['fiscal_end'], config('accounting.currency'), $f['retained_account']);
    }

    private function sourceJournal(array $f, int $nominal, string $type, string $date = '2026-10-07', bool $post = true): int
    {
        $id = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id, 'entry_date' => $date, 'currency' => config('accounting.currency')]);
        DB::table('journal_lines')->insert([
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $id, 'line_number' => 1, 'chart_account_id' => $f['charts']['asset'],
                'debit' => $type === 'revenue' ? '100.00' : '0.00', 'credit' => $type === 'revenue' ? '0.00' : '100.00'],
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $id, 'line_number' => 2, 'chart_account_id' => $nominal,
                'debit' => $type === 'revenue' ? '0.00' : '100.00', 'credit' => $type === 'revenue' ? '100.00' : '0.00'],
        ]);
        if ($post) {
            DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
        }

        return $id;
    }

    private function nominalFixture(string $type = 'revenue', bool $selected = true, bool $zeroNet = false): array
    {
        $f = $this->fixture();
        if ($type === 'expense') {
            $categoryEvidence = DB::table('operational_reconciliation_categories')->where('review_id', $f['review'])
                ->where('user_id', $f['owner']->id)->where('category_id', $f['category']);
            $savedCategoryEvidence = (array) $categoryEvidence->firstOrFail();
            $categoryEvidence->delete();
            DB::table('categories')->where('id', $f['category'])->update(['type' => 'expense']);
            DB::table('chart_of_accounts')->where('id', $f['charts']['revenue'])->update(['type' => 'expense']);
            DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])->where('chart_account_id', $f['charts']['revenue'])->update(['type_snapshot' => 'expense']);
            DB::table('operational_reconciliation_categories')->insert(array_replace($savedCategoryEvidence, ['category_type_snapshot' => 'expense']));
        }
        $journal = $this->sourceJournal($f, $f['charts']['revenue'], $type);
        $balance = $type === 'revenue' ? '200.00' : '0.00';
        $base = ['user_id' => $f['owner']->id, 'review_id' => $f['review']];
        $journals = [$journal];
        if ($zeroNet) {
            $expense = DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id, 'code' => 'EXPENSE', 'name' => 'Expense',
                'type' => 'expense', 'cash_role' => 'non_cash', 'is_active' => 1]);
            $journals[] = $this->sourceJournal($f, $expense, 'expense');
            $balance = '100.00';
        }
        foreach ($journals as $id) {
            DB::table('operational_reconciliation_journals')->insert($base + ['journal_entry_id' => $id]);
            $line = DB::table('journal_lines')->where('journal_entry_id', $id)->where('chart_account_id', $f['charts']['asset'])->first();
            DB::table('operational_reconciliation_attributions')->insert($base + ['journal_entry_id' => $id, 'journal_line_id' => $line->id,
                'target_kind' => 'account', 'account_id' => $f['account'], 'purpose' => 'existing_history', 'amount' => '100.00']);
        }
        DB::table('accounts')->where('id', $f['account'])->update(['balance' => $balance]);
        DB::table('operational_reconciliation_accounts')->where('review_id', $f['review'])->update(['stored_balance_snapshot' => $balance]);
        if (! $selected) {
            DB::table('operational_accounting_category_scope')->where('user_id', $f['owner']->id)->delete();
            DB::table('operational_reconciliation_categories')->where('review_id', $f['review'])->delete();
            DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])->where('chart_account_id', $f['charts']['revenue'])->delete();
        }
        $f['source_journal'] = $journal;
        $f['digest'] = $this->digest($f);
        $this->complete($f);

        return $this->fiscalArguments($f);
    }

    private function assertCleared(array $f, array $before): void
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
            if (! in_array($table, ['operational_accounting_integrations', 'journal_entries', 'journal_lines', 'fiscal_year_closes'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
    }

    public static function relevantStates(): array
    {
        return [['revenue', 'reconciling'], ['expense', 'reconciling'], ['revenue', 'ready'], ['expense', 'ready']];
    }

    #[DataProvider('relevantStates')]
    public function test_generated_selected_nominal_lines_invalidate_only_binding(string $type, string $state): void
    {
        $f = $this->nominalFixture($type);
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $close = $this->closeYear($f);
        $this->assertTrue($close->journalEntry->isPosted());
        $this->assertSame('2026-10-31', $close->journalEntry->entry_date->toDateString());
        $this->assertSame([$f['charts']['revenue'], $f['charts']['equity']], $close->journalEntry->lines->pluck('chart_account_id')->all());
        $this->assertSame($type === 'revenue' ? ['100.00', '0.00'] : ['0.00', '100.00'], $close->journalEntry->lines->pluck('debit')->all());
        $this->assertCleared($f, $before);
    }

    public static function irrelevantKinds(): array
    {
        return [['source membership'], ['zero lines']];
    }

    #[DataProvider('irrelevantKinds')]
    public function test_reviewed_source_membership_retained_equity_and_zero_lines_do_not_imply_relevance(string $kind): void
    {
        $f = $kind === 'source membership' ? $this->nominalFixture(selected: false) : $this->fiscalArguments($this->boundFixture());
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        if ($kind === 'source membership') {
            $this->assertDatabaseHas('operational_reconciliation_journals', ['review_id' => $f['review'], 'journal_entry_id' => $f['source_journal']]);
        }
        $this->assertDatabaseMissing('operational_reconciliation_charts', ['review_id' => $f['review'], 'chart_account_id' => $f['charts']['equity']]);
        $before = $this->allFixtureRows($f['owner']->id);
        $close = $this->closeYear($f);
        $after = $this->allFixtureRows($f['owner']->id);
        $this->assertSame($before['operational_accounting_integrations'], $after['operational_accounting_integrations']);
        foreach ($before as $table => $rows) {
            if (str_starts_with($table, 'operational_reconciliation_')) {
                $this->assertSame($rows, $after[$table]);
            }
        }
        $this->assertSame($kind === 'zero lines', $close->journal_entry_id === null);
    }

    public function test_zero_profit_nonzero_nominal_lines_remain_relevant_without_retained_line(): void
    {
        $f = $this->nominalFixture(zeroNet: true);
        $before = $this->allFixtureRows($f['owner']->id);
        $close = $this->closeYear($f);
        $this->assertCount(2, $close->journalEntry->lines);
        $this->assertNotContains($f['charts']['equity'], $close->journalEntry->lines->pluck('chart_account_id')->all());
        $this->assertCleared($f, $before);
    }

    public function test_active_relevant_close_rejects_but_unrelated_close_is_allowed(): void
    {
        foreach ([true, false] as $selected) {
            $f = $this->nominalFixture(selected: $selected);
            DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => now()]);
            $before = $this->allFixtureRows($f['owner']->id);
            if ($selected) {
                try {
                    $this->closeYear($f);
                    $this->fail('Relevant ACTIVE close committed.');
                } catch (AccountingConflict $e) {
                    $this->assertSame(AccountingConflictReason::ReconciliationState, $e->reason);
                }
                $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
            } else {
                $this->assertNotNull($this->closeYear($f)->journal_entry_id);
                $this->assertSame($before['operational_accounting_integrations'], $this->allFixtureRows($f['owner']->id)['operational_accounting_integrations']);
            }
        }
    }

    public static function writeStages(): array
    {
        return [['insert into `journal_entries`'], ['insert into `journal_lines`'], ['update `journal_entries`'],
            ['insert into `fiscal_year_closes`'], ['update `operational_accounting_integrations`']];
    }

    #[DataProvider('writeStages')]
    public function test_each_write_failure_rolls_back_close_and_binding_then_retry_creates_one_close(string $prefix): void
    {
        $f = $this->nominalFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $before = $this->allFixtureRows($f['owner']->id);
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($prefix): void {
            if (str_starts_with($q->sql, $prefix)) {
                throw new RuntimeException('Injected fiscal-close failure');
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->closeYear($f);
            $this->fail('Failed close committed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected fiscal-close failure', $e->getMessage());
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->closeYear($f);
        $this->assertCleared($f, $before);
        $this->assertSame(count($before['journal_entries']) + 1, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
        $this->assertSame(1, DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->count());
    }

    public function test_no_gate_synthesis_invalid_owner_and_owner_isolation(): void
    {
        $f = $this->nominalFixture();
        $other = $this->nominalFixture();
        $before = $this->allFixtureRows($other['owner']->id);
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->delete();
        $this->closeYear($f);
        $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $f['owner']->id]);
        $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
        try {
            (new CloseFiscalYear)->execute(new User, '2026-10-01', '2026-10-31', config('accounting.currency'), $f['retained_account']);
            $this->fail('Unsaved owner accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
        }
    }

    public static function firstCloseOutcomes(): array
    {
        return [['ready', true], ['ready', false], ['close-fiscal', true]];
    }

    #[DataProvider('firstCloseOutcomes')]
    public function test_close_first_serializes_waiting_ready_or_second_close(string $operation, bool $commit): void
    {
        $f = $this->nominalFixture();
        DB::beginTransaction();
        try {
            $this->closeYear($f);
            $job = $this->startWorker($f, $operation);
            $this->assertWorkerWaiting($job);
            $commit ? DB::commit() : DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $result = $this->waitEvent($job, 'result');
        if (! $commit) {
            $this->assertTrue($result['ok'], $result['message'] ?? '');
            $this->assertDatabaseMissing('fiscal_year_closes', ['user_id' => $f['owner']->id]);
        } else {
            $this->assertFalse($result['ok']);
            $this->assertSame($operation === 'ready' ? 'ReconciliationState' : 'FiscalYearOverlap', $result['reason']);
            $this->assertSame(1, DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->count());
        }
    }

    public function test_ready_first_close_waits_then_demotes_without_changing_evidence(): void
    {
        $f = $this->nominalFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        $job = null;
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($f, &$job): void {
            if (str_starts_with($q->sql, 'update `operational_accounting_integrations`') && $job === null) {
                $job = $this->startWorker($f, 'close-fiscal');
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
        $this->assertCleared($f, $before);
    }

    public function test_different_owner_is_independent(): void
    {
        $f = $this->nominalFixture();
        $other = $this->nominalFixture();
        $before = $this->allFixtureRows($other['owner']->id);
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $other['owner']->id)->lockForUpdate()->first();
            $job = $this->startWorker($f, 'close-fiscal');
            $result = $this->waitEvent($job, 'result');
            $this->assertTrue($result['ok'], $result['message'] ?? '');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->allFixtureRows($other['owner']->id));
    }

    public static function postingOutcomes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('postingOutcomes')]
    public function test_source_post_first_is_current_or_leaves_existing_draft_blocker(bool $commit): void
    {
        $f = $this->nominalFixture();
        $draft = $this->sourceJournal($f, $f['charts']['revenue'], 'revenue', post: false);
        DB::beginTransaction();
        try {
            (new PostJournalEntry)->execute($f['owner'], $draft);
            $job = $this->startWorker($f, 'close-fiscal');
            $this->assertWorkerWaiting($job);
            $commit ? DB::commit() : DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $result = $this->waitEvent($job, 'result');
        if ($commit) {
            $this->assertTrue($result['ok'], $result['message'] ?? '');
            $journal = DB::table('fiscal_year_closes')->where('id', $result['result'])->value('journal_entry_id');
            $this->assertSame('200.00', DB::table('journal_lines')->where('journal_entry_id', $journal)->where('chart_account_id', $f['charts']['revenue'])->value('debit'));
        } else {
            $this->assertFalse($result['ok']);
            $this->assertSame('FiscalYearDraftJournals', $result['reason']);
            $this->assertDatabaseMissing('fiscal_year_closes', ['user_id' => $f['owner']->id]);
        }
    }

    public function test_exact_lock_sequence_and_transaction_owned_sources_precede_evidence(): void
    {
        $f = $this->nominalFixture();
        $queries = [];
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            $queries[] = ['query' => $q->sql, 'bindings' => $q->bindings];
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->closeYear($f);
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $locks = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'for update') || str_contains($q['query'], 'lock in share mode')));
        $tables = ['users', 'accounting_periods', 'fiscal_year_closes', 'operational_accounting_integrations',
            'operational_accounting_account_scope', 'operational_accounting_category_scope', 'operational_account_mappings', 'operational_category_mappings',
            'chart_of_accounts', 'journal_entries', 'journal_lines', 'opening_balance_batches',
            'operational_reconciliation_charts', 'operational_reconciliation_journals', 'operational_reconciliation_reviews'];
        $this->assertCount(count($tables), $locks);
        foreach ($tables as $i => $table) {
            $this->assertStringContainsString('from `'.$table.'`', $locks[$i]['query']);
            $this->assertSame($f['owner']->id, $locks[$i]['bindings'][0]);
        }
        $ids = array_values($f['charts']);
        sort($ids);
        $this->assertSame([$f['owner']->id, ...$ids], $locks[8]['bindings']);
        $this->assertStringContainsString('order by `id` asc', $locks[8]['query']);
        $this->assertSame([$f['owner']->id, '2026-10-01', '2026-10-31'], $locks[9]['bindings']);
        $this->assertStringContainsString('order by `id` asc', $locks[9]['query']);
        $this->assertStringContainsString('order by `journal_entry_id` asc, `id` asc', $locks[10]['query']);
        $this->assertSame([$f['owner']->id, 'draft', '2026-10-01', '2026-10-31'], $locks[11]['bindings']);
        $provenance = array_find_key($queries, fn ($q) => str_starts_with($q['query'], 'insert into `fiscal_year_closes`'));
        $evidence = array_search($locks[12], $queries, true);
        $this->assertNotNull($provenance);
        $this->assertLessThan($evidence, $provenance);
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'insert into `journal_line_allocations`')
            || str_starts_with($q['query'], 'insert into `cash_flow_journal_completions`'))));
    }

    /** Connection/callback mechanics only; current-state assertions stay below. */
    private function beforeOwnerProbe(callable $coordination, callable $operation): int
    {
        $this->configureProbeConnection('c3_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($coordination, &$attempts): void {
            if (str_contains($sql, 'from `users`') && str_contains($sql, 'for update')) {
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

    public static function sourceChanges(): array
    {
        return [['new journal'], ['status'], ['date'], ['chart'], ['amount']];
    }

    #[DataProvider('sourceChanges')]
    public function test_old_snapshot_cannot_authorize_changed_source_manifest_or_aggregation(string $change): void
    {
        $f = $this->nominalFixture();
        $source = $change === 'date' || $change === 'status'
            ? $this->sourceJournal($f, $f['charts']['revenue'], 'revenue', $change === 'date' ? '2026-11-01' : '2026-10-08', post: false)
            : $f['source_journal'];
        $extra = DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id, 'code' => 'EXTRA', 'name' => 'Extra revenue',
            'type' => 'revenue', 'cash_role' => 'non_cash', 'is_active' => 1]);
        // Raw changes to draft sources are structurally legitimate. Posted
        // source identity/amount changes use a new draft instead of bypassing immutability.
        if (in_array($change, ['chart', 'amount'], true)) {
            $source = $this->sourceJournal($f, $f['charts']['revenue'], 'revenue', post: false);
        }
        $queries = [];
        $originalDispatcher = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $source, $extra, $change): void {
                if ($attempt !== 1) {
                    return;
                }
                $oldHeaders = DB::table('journal_entries')->where('user_id', $f['owner']->id)->orderBy('id')->get()->all();
                $oldLines = DB::table('journal_lines')->where('user_id', $f['owner']->id)->orderBy('id')->get()->all();
                $default = DB::getDefaultConnection();
                try {
                    DB::setDefaultConnection('c3_probe');
                    DB::transaction(function () use ($f, $source, $extra, $change): void {
                        DB::table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                        if ($change === 'new journal') {
                            $this->sourceJournal($f, $f['charts']['revenue'], 'revenue');
                        } else {
                            if ($change === 'date') {
                                DB::table('journal_entries')->where('id', $source)->update(['entry_date' => '2026-10-08']);
                            } elseif ($change === 'chart') {
                                DB::table('journal_lines')->where('journal_entry_id', $source)->where('line_number', 2)->update(['chart_account_id' => $extra]);
                            } elseif ($change === 'amount') {
                                DB::table('journal_lines')->where('journal_entry_id', $source)->where('line_number', 1)->update(['debit' => '200.00']);
                                DB::table('journal_lines')->where('journal_entry_id', $source)->where('line_number', 2)->update(['credit' => '200.00']);
                            }
                            DB::table('journal_entries')->where('id', $source)->update(['status' => 'posted', 'posted_at' => now()]);
                        }
                    });
                } finally {
                    DB::setDefaultConnection($default);
                }
                $this->assertEquals($oldHeaders, DB::table('journal_entries')->where('user_id', $f['owner']->id)->orderBy('id')->get()->all());
                $this->assertEquals($oldLines, DB::table('journal_lines')->where('user_id', $f['owner']->id)->orderBy('id')->get()->all());
                $this->assertNotEquals($oldHeaders, DB::connection('c3_probe')->table('journal_entries')->where('user_id', $f['owner']->id)->orderBy('id')->get()->all());
            }, fn () => $this->closeYear($f));
        } finally {
            DB::connection()->setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(2, $attempts);
        $ownerPositions = array_keys(array_filter($queries, fn ($q) => str_contains($q, 'from `users`') && str_contains($q, 'for update')));
        $this->assertCount(2, $ownerPositions);
        foreach (array_slice($queries, $ownerPositions[0], $ownerPositions[1] - $ownerPositions[0]) as $query) {
            if (str_contains($query, 'from `chart_of_accounts`') && str_contains($query, 'for update')) {
                $firstChartLock = true;
                $this->assertFalse(isset($headerLock), 'No late charts after authoritative headers.');
            }
            if (str_contains($query, 'from `journal_entries`') && str_contains($query, 'for update')) {
                $headerLock = true;
                $this->assertTrue($firstChartLock ?? false);
            }
        }
        $journal = DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->value('journal_entry_id');
        $this->assertSame($change === 'chart' ? '100.00' : ($change === 'amount' ? '300.00' : '200.00'),
            DB::table('journal_lines')->where('journal_entry_id', $journal)->where('chart_account_id', $f['charts']['revenue'])->value('debit'));
        if ($change === 'chart') {
            $this->assertSame('100.00', DB::table('journal_lines')->where('journal_entry_id', $journal)->where('chart_account_id', $extra)->value('debit'));
        }
        $this->assertSame(1, DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->count());
    }

    public function test_old_snapshot_cannot_hide_existing_overlap(): void
    {
        $f = $this->nominalFixture();
        $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f): void {
            $this->assertSame(1, $attempt);
            $this->assertFalse(DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->exists());
            $default = DB::getDefaultConnection();
            try {
                DB::setDefaultConnection('c3_probe');
                $probeOwner = User::on('c3_probe')->findOrFail($f['owner']->id);
                $this->closeYear(array_replace($f, ['owner' => $probeOwner]));
            } finally {
                DB::setDefaultConnection($default);
            }
            $this->assertFalse(DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->exists());
            $this->assertSame(1, DB::connection('c3_probe')->table('fiscal_year_closes')->where('user_id', $f['owner']->id)->count());
        }, function () use ($f): void {
            try {
                $this->closeYear($f);
                $this->fail('Current overlap was hidden.');
            } catch (AccountingConflict $e) {
                $this->assertSame(AccountingConflictReason::FiscalYearOverlap, $e->reason);
            }
        });
        $this->assertSame(1, $attempts);
        $this->assertSame(1, DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->count());
        $this->assertSame(3, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
    }

    public function test_current_binding_and_membership_cannot_be_hidden_by_old_snapshot(): void
    {
        $f = $this->nominalFixture();
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['reconciliation_reference' => null, 'reconciled_at' => null]);
        $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $gate): void {
            $this->assertSame(1, $attempt);
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
            DB::connection('c3_probe')->transaction(function () use ($f, $gate): void {
                $probe = DB::connection('c3_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
                    ->update(['reconciliation_reference' => $gate->reconciliation_reference, 'reconciled_at' => $gate->reconciled_at]);
            });
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
            $this->assertSame($f['reference'], DB::connection('c3_probe')->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
        }, fn () => $this->closeYear($f));
        $this->assertSame(1, $attempts);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'reconciling',
            'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_nested_caller_receives_discovery_conflict_without_retrying_stale_snapshot(): void
    {
        $f = $this->nominalFixture();
        DB::beginTransaction();
        try {
            $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f): void {
                $this->assertSame(1, $attempt, 'Nested calls must not rediscover inside an existing snapshot.');
                $old = DB::table('journal_entries')->where('user_id', $f['owner']->id)->count();
                $default = DB::getDefaultConnection();
                try {
                    DB::setDefaultConnection('c3_probe');
                    DB::transaction(function () use ($f): void {
                        DB::table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                        $this->sourceJournal($f, $f['charts']['revenue'], 'revenue');
                    });
                } finally {
                    DB::setDefaultConnection($default);
                }
                $this->assertSame($old, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
                $this->assertSame($old + 1, DB::connection('c3_probe')->table('journal_entries')->where('user_id', $f['owner']->id)->count());
            }, function () use ($f): void {
                try {
                    $this->closeYear($f);
                    $this->fail('Nested stale manifest accepted.');
                } catch (AccountingConflict $e) {
                    $this->assertSame(AccountingConflictReason::ReconciliationDiscoveryConflict, $e->reason);
                    $this->assertSame(1, DB::transactionLevel());
                }
            });
            $this->assertSame(1, $attempts);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseMissing('fiscal_year_closes', ['user_id' => $f['owner']->id]);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => $f['reference']]);
        $this->closeYear($f);
        $this->assertSame(1, DB::table('fiscal_year_closes')->where('user_id', $f['owner']->id)->count());
    }

    public function test_current_retained_state_and_opening_blocker_are_not_hidden_by_old_snapshot(): void
    {
        foreach (['retained', 'opening'] as $change) {
            $f = $this->nominalFixture();
            $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
            $attempts = $this->beforeOwnerProbe(function ($attempt) use ($f, $change): void {
                $this->assertSame(1, $attempt);
                $this->assertSame(1, DB::table('chart_of_accounts')->where('id', $f['retained_account'])->value('is_active'));
                $this->assertFalse(DB::table('opening_balance_batches')->where('user_id', $f['owner']->id)->exists());
                DB::connection('c3_probe')->transaction(function () use ($f, $change): void {
                    $probe = DB::connection('c3_probe');
                    $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                    if ($change === 'retained') {
                        $probe->table('chart_of_accounts')->where('id', $f['retained_account'])->update(['is_active' => 0]);
                    } else {
                        $probe->table('opening_balance_batches')->insert(['user_id' => $f['owner']->id,
                            'opening_date' => '2026-10-08', 'currency' => config('accounting.currency')]);
                    }
                });
                $this->assertSame(1, DB::table('chart_of_accounts')->where('id', $f['retained_account'])->value('is_active'));
                $this->assertFalse(DB::table('opening_balance_batches')->where('user_id', $f['owner']->id)->exists());
            }, function () use ($f, $change): void {
                try {
                    $this->closeYear($f);
                    $this->fail('Current blocker was hidden.');
                } catch (AccountingConflict $e) {
                    $this->assertSame($change === 'retained' ? AccountingConflictReason::FiscalYearRetainedEarningsAccount : AccountingConflictReason::FiscalYearDraftOpeningBalances, $e->reason);
                }
            });
            $this->assertSame(1, $attempts);
            $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
            $this->assertDatabaseMissing('fiscal_year_closes', ['user_id' => $f['owner']->id]);
        }
    }
}
