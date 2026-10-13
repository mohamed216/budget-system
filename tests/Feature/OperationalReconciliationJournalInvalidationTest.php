<?php

namespace Tests\Feature;

use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\JournalEntry;
use App\Models\User;
use App\OperationalAccounting\Actions\MarkOperationalAccountingReady;
use App\OperationalAccounting\OperationalReconciliationHistoryDependencies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OperationalReconciliationJournalInvalidationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function draftJournal(array $f, ?int $chartId = null): int
    {
        $journal = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
            'entry_date' => '2026-10-12', 'currency' => config('accounting.currency')]);
        DB::table('journal_lines')->insert([
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $journal, 'line_number' => 1,
                'chart_account_id' => $chartId ?? $f['charts']['asset'], 'debit' => '0.01', 'credit' => '0.00'],
            ['user_id' => $f['owner']->id, 'journal_entry_id' => $journal, 'line_number' => 2,
                'chart_account_id' => $f['charts']['equity'], 'debit' => '0.00', 'credit' => '0.01'],
        ]);

        return $journal;
    }

    private function unrelatedChart(array $f): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id,
            'code' => 'UNRELATED', 'name' => 'Unrelated', 'type' => 'asset', 'cash_role' => 'non_cash', 'is_active' => 1]);
    }

    private function mutate(array $f, string $operation, int $journal): JournalEntry
    {
        return $operation === 'post'
            ? (new PostJournalEntry)->execute($f['owner'], $journal)
            : (new ReverseJournalEntry)->execute($f['owner'], $journal);
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
            if (str_starts_with($table, 'operational_reconciliation_') || in_array($table,
                ['accounts', 'categories', 'transactions', 'chart_of_accounts', 'operational_account_mappings',
                    'operational_category_mappings', 'operational_accounting_account_scope', 'operational_accounting_category_scope'], true)) {
                $this->assertSame($rows, $after[$table], $table);
            }
        }
    }

    public static function operations(): array
    {
        return [['post'], ['reverse']];
    }

    public static function statesAndOperations(): array
    {
        return [['post', 'reconciling'], ['post', 'ready'], ['reverse', 'reconciling'], ['reverse', 'ready']];
    }

    #[DataProvider('statesAndOperations')]
    public function test_relevant_success_invalidates_only_binding_and_preserves_completed_evidence(string $operation, string $state): void
    {
        $f = $this->boundFixture();
        if ($state === 'ready') {
            (new MarkOperationalAccountingReady)->execute($f['owner']);
        }
        $journal = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
        $before = $this->allFixtureRows($f['owner']->id);
        $original = (array) DB::table('journal_entries')->where('id', $journal)->first();
        $result = $this->mutate($f, $operation, $journal);
        $this->assertTrue($result->isPosted());
        $this->assertCleared($f, $before);
        if ($operation === 'reverse') {
            $this->assertSame($original, (array) DB::table('journal_entries')->where('id', $journal)->first());
            $this->assertSame($journal, $result->reversal_of_id);
        } else {
            $this->assertSame($journal, $result->id);
            $this->assertSame(2, $result->version);
        }
    }

    #[DataProvider('operations')]
    public function test_unrelated_history_and_foreign_owners_preserve_binding(string $operation): void
    {
        $f = $this->boundFixture();
        $foreign = $this->boundFixture();
        $journal = $this->draftJournal($f, $this->unrelatedChart($f));
        if ($operation === 'reverse') {
            (new PostJournalEntry)->execute($f['owner'], $journal);
        }
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $foreignRows = $this->allFixtureRows($foreign['owner']->id);
        $this->assertTrue($this->mutate($f, $operation, $journal)->isPosted());
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        try {
            $this->mutate($f, $operation, $foreign['journal']);
            $this->fail('Foreign history was accessible.');
        } catch (ModelNotFoundException) {
            $this->assertSame($foreignRows, $this->allFixtureRows($foreign['owner']->id));
        }
    }

    public function test_posted_noop_and_duplicate_reversal_do_not_invalidate(): void
    {
        $f = $this->boundFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        (new PostJournalEntry)->execute($f['owner'], $f['journal']);
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $journal = $this->draftJournal($f, $this->unrelatedChart($f));
        (new PostJournalEntry)->execute($f['owner'], $journal);
        $reversal = (new ReverseJournalEntry)->execute($f['owner'], $journal);
        $before = $this->allFixtureRows($f['owner']->id);
        foreach ([$journal, $reversal->id] as $id) {
            try {
                (new ReverseJournalEntry)->execute($f['owner'], $id);
                $this->fail('Duplicate or reversal-of-reversal accepted.');
            } catch (AccountingConflict) {
                $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
            }
        }
    }

    public function test_reversal_of_unrepresented_history_touching_reviewed_target_still_invalidates(): void
    {
        $f = $this->boundFixture();
        $journal = $this->draftJournal($f);
        // Simulate externally introduced posted history. Do not weaken completion
        // to approve a review that omits a posted participating journal.
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()]);
        $this->assertDatabaseMissing('operational_reconciliation_journals', ['review_id' => $f['review'], 'journal_entry_id' => $journal]);
        $before = $this->allFixtureRows($f['owner']->id);
        (new ReverseJournalEntry)->execute($f['owner'], $journal);
        $this->assertCleared($f, $before);
    }

    #[DataProvider('operations')]
    public function test_active_relevant_rejects_but_unrelated_history_is_allowed(string $operation): void
    {
        $f = $this->boundFixture();
        $journal = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => now()]);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            $this->mutate($f, $operation, $journal);
            $this->fail('Active reconciliation was invalidated.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationState, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $unrelated = $this->draftJournal($f, $this->unrelatedChart($f));
        (new PostJournalEntry)->execute($f['owner'], $unrelated);
        if ($operation === 'reverse') {
            (new ReverseJournalEntry)->execute($f['owner'], $unrelated);
        }
        $this->assertEquals($before['operational_accounting_integrations'], $this->allFixtureRows($f['owner']->id)['operational_accounting_integrations']);
    }

    private function cashFixture(): array
    {
        $f = $this->fixture();
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['cash_role' => 'cash']);
        DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])
            ->where('chart_account_id', $f['charts']['asset'])->update(['cash_role_snapshot' => 'cash']);
        $credit = DB::table('journal_lines')->where('journal_entry_id', $f['journal'])->where('line_number', 2)->value('id');
        DB::table('journal_line_allocations')->insert(['user_id' => $f['owner']->id, 'journal_entry_id' => $f['journal'],
            'debit_line_id' => $f['assetLine'], 'credit_line_id' => $credit, 'amount' => '100.00', 'category' => 'financing']);
        DB::table('cash_flow_journal_completions')->insert(['user_id' => $f['owner']->id,
            'journal_entry_id' => $f['journal'], 'completed_at' => now()->format('Y-m-d H:i:s.u')]);
        $f['digest'] = $this->digest($f);
        $this->complete($f);

        return $f;
    }

    #[DataProvider('operations')]
    public function test_cash_mutation_seals_completion_and_invalidates_atomically(string $operation): void
    {
        $f = $this->cashFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $journal = $f['journal'];
        if ($operation === 'post') {
            $journal = $this->draftJournal($f);
            $lines = DB::table('journal_lines')->where('journal_entry_id', $journal)->orderBy('line_number')->get();
            DB::table('journal_line_allocations')->insert(['user_id' => $f['owner']->id, 'journal_entry_id' => $journal,
                'debit_line_id' => $lines[0]->id, 'credit_line_id' => $lines[1]->id, 'amount' => '0.01', 'category' => 'financing']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $result = $this->mutate($f, $operation, $journal);
        $this->assertTrue($result->isPosted());
        $this->assertDatabaseHas('cash_flow_journal_completions', ['user_id' => $f['owner']->id, 'journal_entry_id' => $result->id]);
        $allocation = DB::table('journal_line_allocations')->where('journal_entry_id', $result->id)->first();
        $this->assertSame('financing', $allocation->category);
        $this->assertSame($operation === 'post' ? '0.01' : '100.00', $allocation->amount);
        if ($operation === 'reverse') {
            $this->assertSame($f['journal'], $result->reversal_of_id);
            $this->assertSame('100.00', DB::table('journal_lines')->where('id', $allocation->debit_line_id)->value('debit'));
            $this->assertSame('100.00', DB::table('journal_lines')->where('id', $allocation->credit_line_id)->value('credit'));
        }
        $this->assertCleared($f, $before);
    }

    public static function failures(): array
    {
        return [['post', 'invalidation'], ['post', 'status'], ['post', 'completion'],
            ['reverse', 'invalidation'], ['reverse', 'status'], ['reverse', 'completion'], ['reverse', 'allocation']];
    }

    #[DataProvider('failures')]
    public function test_every_write_failure_rolls_back_financial_mutation_and_binding(string $operation, string $failure): void
    {
        $f = $this->cashFixture();
        (new MarkOperationalAccountingReady)->execute($f['owner']);
        $journal = $f['journal'];
        if ($operation === 'post') {
            $journal = $this->draftJournal($f);
            $lines = DB::table('journal_lines')->where('journal_entry_id', $journal)->orderBy('line_number')->get();
            DB::table('journal_line_allocations')->insert(['user_id' => $f['owner']->id, 'journal_entry_id' => $journal,
                'debit_line_id' => $lines[0]->id, 'credit_line_id' => $lines[1]->id, 'amount' => '0.01', 'category' => 'financing']);
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $prefix = match ($failure) {
            'invalidation' => 'update `operational_accounting_integrations`',
            'status' => 'update `journal_entries`',
            'completion' => 'insert into `cash_flow_journal_completions`',
            'allocation' => 'insert into `journal_line_allocations`',
        };
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($prefix): void {
            if (str_starts_with($q->sql, $prefix)) {
                throw new RuntimeException('Injected history failure');
            }
        });
        DB::connection()->setEventDispatcher($dispatcher);
        try {
            $this->mutate($f, $operation, $journal);
            $this->fail('Failed mutation committed.');
        } catch (RuntimeException $e) {
            $this->assertSame('Injected history failure', $e->getMessage());
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    #[DataProvider('operations')]
    public function test_owner_identity_rejected_and_no_gate_synthesized(string $operation): void
    {
        $f = $this->fixture();
        $journal = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->mutate($f, $operation, $journal);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->delete();
        $journal = $this->draftJournal($f);
        if ($operation === 'reverse') {
            (new PostJournalEntry)->execute($f['owner'], $journal);
        }
        $this->mutate($f, $operation, $journal);
        $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $f['owner']->id]);
        try {
            $action = $operation === 'post' ? new PostJournalEntry : new ReverseJournalEntry;
            $action->execute(new User, $journal);
            $this->fail('Unsaved owner accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('operational_accounting_integrations', ['user_id' => $f['owner']->id]);
        }
    }

    #[DataProvider('operations')]
    public function test_rolled_back_mutation_first_allows_waiting_ready(string $operation): void
    {
        $f = $this->boundFixture();
        $journal = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
        DB::beginTransaction();
        try {
            $this->mutate($f, $operation, $journal);
            $job = $this->startWorker($f, 'ready');
            $this->assertWorkerWaiting($job);
        } finally {
            DB::rollBack();
        }
        // Rollback above intentionally proves READY can succeed when the mutation
        // did not commit; committed ordering is covered by the next test variant.
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
    }

    #[DataProvider('operations')]
    public function test_committed_mutation_first_waiting_ready_rejects(string $operation): void
    {
        $f = $this->boundFixture();
        $journal = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
        DB::beginTransaction();
        try {
            $this->mutate($f, $operation, $journal);
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

    #[DataProvider('operations')]
    public function test_ready_first_mutation_waits_then_demotes(string $operation): void
    {
        $f = $this->boundFixture();
        $f['history_journal'] = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
        $job = null;
        $original = DB::connection()->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use ($f, $operation, &$job): void {
            if (str_starts_with($q->sql, 'update `operational_accounting_integrations`') && $job === null) {
                $job = $this->startWorker($f, $operation === 'post' ? 'post-journal' : 'reverse-journal');
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

    public function test_different_owner_remains_independent(): void
    {
        $f = $this->boundFixture();
        $foreign = $this->boundFixture();
        $f['history_journal'] = $this->draftJournal($f);
        $before = $this->allFixtureRows($foreign['owner']->id);
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $foreign['owner']->id)->lockForUpdate()->first();
            $job = $this->startWorker($f, 'post-journal');
            $result = $this->waitEvent($job, 'result');
            $this->assertTrue($result['ok'], $result['message'] ?? '');
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->allFixtureRows($foreign['owner']->id));
    }

    #[DataProvider('operations')]
    public function test_source_and_evidence_lock_order(string $operation): void
    {
        $f = $this->boundFixture();
        $journal = $operation === 'post' ? $this->draftJournal($f) : $f['journal'];
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
            $this->mutate($f, $operation, $journal);
        } finally {
            DB::connection()->setEventDispatcher($original);
        }
        $positions = [];
        foreach ($queries as $index => $query) {
            preg_match('/from `([^`]+)`/', $query, $match);
            $positions[$match[1]] ??= $index;
        }
        $tables = ['users', 'accounting_periods', 'fiscal_year_closes', 'operational_accounting_integrations',
            'operational_accounting_account_scope', 'operational_accounting_category_scope', 'operational_account_mappings',
            'operational_category_mappings', 'chart_of_accounts', 'journal_entries', 'journal_lines',
            'journal_line_allocations', 'cash_flow_journal_completions'];
        if ($operation === 'reverse') {
            $tables[] = 'opening_balance_batches';
        }
        array_push($tables, 'operational_reconciliation_charts', 'operational_reconciliation_journals', 'operational_reconciliation_reviews');
        foreach ($tables as $i => $table) {
            $this->assertArrayHasKey($table, $positions);
            if ($i > 0) {
                $this->assertLessThan($positions[$table], $positions[$tables[$i - 1]]);
            }
        }
        $this->assertStringContainsString('order by `id` asc', $queries[$positions['chart_of_accounts']]);
    }

    private function emptySupersedingReview(array $f): array
    {
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['reconciliation_reference' => null, 'reconciled_at' => null]);
        foreach (['account', 'category'] as $kind) {
            DB::table('operational_accounting_'.$kind.'_scope')->where('user_id', $f['owner']->id)->delete();
        }
        $header = (array) DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->first();
        unset($header['id']);
        $header['reference'] = (string) Str::uuid();
        $header['supersedes_review_id'] = $f['review'];
        $header['reviewed_by'] = null;
        $header['completed_at'] = null;
        $f['review'] = DB::table('operational_reconciliation_reviews')->insertGetId($header);
        $f['reference'] = $header['reference'];
        $f['digest'] = $this->digest($f);
        $this->complete($f);

        return $f;
    }

    public function test_superseded_membership_does_not_invalidate_current_empty_review(): void
    {
        $f = $this->emptySupersedingReview($this->boundFixture());
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->assertDatabaseMissing('operational_reconciliation_journals', ['review_id' => $f['review']]);
        (new ReverseJournalEntry)->execute($f['owner'], $f['journal']);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    public function test_post_rediscovery_cannot_authorize_old_repeatable_read_chart_manifest(): void
    {
        $f = $this->boundFixture();
        $outside = $this->unrelatedChart($f);
        $journal = $this->draftJournal($f, $outside);
        $line = DB::table('journal_lines')->where('journal_entry_id', $journal)->where('line_number', 1)->value('id');
        $this->configureProbeConnection('history_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($f, $line, $outside, &$attempts): void {
            if (! str_contains($sql, '`users`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $attempts++;
            if ($attempts !== 1) {
                $this->assertSame($f['charts']['asset'], DB::table('journal_lines')->where('id', $line)->value('chart_account_id'));

                return;
            }
            $this->assertSame($outside, DB::table('journal_lines')->where('id', $line)->value('chart_account_id'));
            DB::connection('history_probe')->transaction(function () use ($f, $line): void {
                $probe = DB::connection('history_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('journal_lines')->where('id', $line)->update(['chart_account_id' => $f['charts']['asset']]);
            });
            $this->assertSame($f['charts']['asset'], DB::connection('history_probe')->table('journal_lines')->where('id', $line)->value('chart_account_id'));
            $this->assertSame($outside, DB::table('journal_lines')->where('id', $line)->value('chart_account_id'));
        });
        $invalidations = 0;
        $originalDispatcher = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $q) use (&$invalidations): void {
            if (str_starts_with($q->sql, 'update `operational_accounting_integrations`')) {
                $invalidations++;
            }
        });
        $connection->setEventDispatcher($dispatcher);
        try {
            $this->assertTrue((new PostJournalEntry)->execute($f['owner'], $journal)->isPosted());
        } finally {
            $callbacks->setValue($connection, $original);
            $connection->setEventDispatcher($originalDispatcher);
        }
        $this->assertSame(2, $attempts, 'A missing earlier chart must restart the entire transaction.');
        $this->assertSame(1, $invalidations, 'The failed discovery attempt must not publish invalidation.');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_current_binding_and_membership_are_not_hidden_by_old_repeatable_read_snapshot(): void
    {
        $participating = $this->boundFixture();
        $f = $this->emptySupersedingReview($participating);
        $old = DB::table('operational_reconciliation_reviews')->where('id', $participating['review'])->first();
        $this->configureProbeConnection('history_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($f, $old, &$attempts): void {
            if (! str_contains($sql, '`users`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $attempts++;
            $this->assertSame($f['reference'], DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
            DB::connection('history_probe')->transaction(function () use ($f, $old): void {
                $probe = DB::connection('history_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
                    ->update(['reconciliation_reference' => $old->reference, 'reconciled_at' => $old->completed_at]);
            });
            $this->assertSame($old->reference, DB::connection('history_probe')->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
            $this->assertSame($f['reference'], DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('reconciliation_reference'));
        });
        try {
            (new ReverseJournalEntry)->execute($f['owner'], $f['journal']);
        } finally {
            $callbacks->setValue($connection, $original);
        }
        $this->assertSame(1, $attempts, 'Current binding reads must work without restarting to hide the snapshot.');
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_opening_provenance_is_current_despite_old_repeatable_read_snapshot(): void
    {
        $f = $this->boundFixture();
        // Ordinary reversal would reject this unresolved role. The externally
        // committed opening link must invoke the existing special reversal rules.
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['cash_role' => null]);
        $this->configureProbeConnection('history_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $connection->beforeExecuting(function ($sql) use ($f): void {
            if (! str_contains($sql, '`users`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $this->assertFalse(DB::table('opening_balance_batches')->where('journal_entry_id', $f['journal'])->exists());
            DB::connection('history_probe')->transaction(function () use ($f): void {
                $probe = DB::connection('history_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $batch = $probe->table('opening_balance_batches')->insertGetId(['user_id' => $f['owner']->id,
                    'opening_date' => '2026-10-06', 'currency' => config('accounting.currency')]);
                $probe->table('opening_balance_lines')->insert([
                    ['user_id' => $f['owner']->id, 'batch_id' => $batch, 'chart_account_id' => $f['charts']['asset'], 'debit' => '100.00', 'credit' => '0.00'],
                    ['user_id' => $f['owner']->id, 'batch_id' => $batch, 'chart_account_id' => $f['charts']['equity'], 'debit' => '0.00', 'credit' => '100.00'],
                ]);
                $probe->table('opening_balance_batches')->where('id', $batch)->update(['status' => 'posted',
                    'journal_entry_id' => $f['journal'], 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
            });
            $this->assertTrue(DB::connection('history_probe')->table('opening_balance_batches')->where('journal_entry_id', $f['journal'])->exists());
            $this->assertFalse(DB::table('opening_balance_batches')->where('journal_entry_id', $f['journal'])->exists());
        });
        try {
            $reversal = (new ReverseJournalEntry)->execute($f['owner'], $f['journal']);
        } finally {
            $callbacks->setValue($connection, $original);
        }
        $this->assertTrue($reversal->isPosted());
        $this->assertDatabaseMissing('cash_flow_journal_completions', ['journal_entry_id' => $reversal->id]);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_incomplete_history_manifest_rejects_before_evidence_queries(): void
    {
        $f = $this->boundFixture();
        $before = $this->allFixtureRows($f['owner']->id);
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        try {
            (new OperationalReconciliationHistoryDependencies)->lockBoundReview($f['owner']->id, $gate, [
                'journal_ids' => [], 'chart_ids' => [], 'lines' => [['user_id' => $f['owner']->id,
                    'journal_entry_id' => $f['journal'], 'chart_account_id' => $f['charts']['asset']]],
            ]);
            $this->fail('Incomplete source manifest accepted.');
        } catch (AccountingConflict $e) {
            $this->assertSame(AccountingConflictReason::ReconciliationDiscoveryConflict, $e->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public static function competingProvenance(): array
    {
        return [['fiscal'], ['reversal']];
    }

    #[DataProvider('competingProvenance')]
    public function test_current_special_exclusions_reject_despite_old_snapshot(string $kind): void
    {
        $f = $this->boundFixture();
        $gate = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->configureProbeConnection('history_probe');
        $connection = DB::connection();
        $callbacks = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $original = $callbacks->getValue($connection);
        $attempts = 0;
        $connection->beforeExecuting(function ($sql) use ($f, $kind, &$attempts): void {
            if (! str_contains($sql, '`users`') || ! str_contains($sql, 'for update')) {
                return;
            }
            $attempts++;
            $table = $kind === 'fiscal' ? 'fiscal_year_closes' : 'journal_entries';
            $field = $kind === 'fiscal' ? 'journal_entry_id' : 'reversal_of_id';
            $this->assertFalse(DB::table($table)->where($field, $f['journal'])->exists());
            DB::connection('history_probe')->transaction(function () use ($f, $kind): void {
                $probe = DB::connection('history_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                if ($kind === 'fiscal') {
                    // Deliberately external provenance: the guard must reject it
                    // without relying on an advisory snapshot or repairing it.
                    $probe->table('fiscal_year_closes')->insert(['user_id' => $f['owner']->id,
                        'start_date' => '2026-10-01', 'end_date' => '2026-10-06', 'currency' => config('accounting.currency'),
                        'retained_earnings_account_id' => $f['charts']['equity'], 'journal_entry_id' => $f['journal'],
                        'closed_at' => now()->format('Y-m-d H:i:s.u')]);
                } else {
                    $reverse = $probe->table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
                        'entry_date' => '2026-10-12', 'currency' => config('accounting.currency'), 'reversal_of_id' => $f['journal']]);
                    $probe->table('journal_lines')->insert([
                        ['user_id' => $f['owner']->id, 'journal_entry_id' => $reverse, 'line_number' => 1,
                            'chart_account_id' => $f['charts']['asset'], 'debit' => '0.00', 'credit' => '100.00'],
                        ['user_id' => $f['owner']->id, 'journal_entry_id' => $reverse, 'line_number' => 2,
                            'chart_account_id' => $f['charts']['equity'], 'debit' => '100.00', 'credit' => '0.00'],
                    ]);
                    $probe->table('journal_entries')->where('id', $reverse)->update(['status' => 'posted', 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
                }
            });
            $this->assertTrue(DB::connection('history_probe')->table($table)->where($field, $f['journal'])->exists());
            $this->assertFalse(DB::table($table)->where($field, $f['journal'])->exists());
        });
        try {
            (new ReverseJournalEntry)->execute($f['owner'], $f['journal']);
            $this->fail('Current special provenance was hidden.');
        } catch (AccountingConflict $e) {
            $this->assertStringContainsString($kind === 'fiscal' ? 'Fiscal-year closing journals' : 'already been reversed', $e->getMessage());
        } finally {
            $callbacks->setValue($connection, $original);
        }
        $this->assertSame(1, $attempts);
        $this->assertEquals($gate, DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    public function test_category_target_post_and_backfill_member_reversal_are_relevant(): void
    {
        $f = $this->boundFixture('historical_backfill');
        $before = $this->allFixtureRows($f['owner']->id);
        $journal = $this->draftJournal($f, $f['charts']['revenue']);
        (new PostJournalEntry)->execute($f['owner'], $journal);
        $this->assertCleared($f, $before);
        $other = $this->boundFixture('historical_backfill');
        $before = $this->allFixtureRows($other['owner']->id);
        $link = DB::table('operational_transaction_journals')->where('transaction_id', $other['transaction'])->first();
        (new ReverseJournalEntry)->execute($other['owner'], $other['journal']);
        $this->assertCleared($other, $before);
        $this->assertEquals($link, DB::table('operational_transaction_journals')->where('transaction_id', $other['transaction'])->first());
    }
}
