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
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithEvidenceProtectionTriggers;
use Tests\TestCase;
use Throwable;

class OperationalReconciliationCompletionTest extends TestCase
{
    use InteractsWithEvidenceProtectionTriggers;

    private array $owners = [];

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertProtectionTriggersInstalledExactly();
    }

    protected function tearDown(): void
    {
        $failure = null;
        $attempt = function (callable $operation) use (&$failure): void {
            try {
                $operation();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        };
        foreach ($this->workers as $worker) {
            $attempt(function () use ($worker): void {
                if (is_resource($worker['process'])) {
                    if (proc_get_status($worker['process'])['running']) {
                        proc_terminate($worker['process']);
                    }
                    foreach (array_slice($worker['pipes'], 1) as $pipe) {
                        if (is_resource($pipe)) {
                            stream_get_contents($pipe);
                        }
                    }
                    foreach ($worker['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($worker['process']);
                }
            });
        }
        $attempt(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
        $attempt(fn () => $this->cleanupFixtures());
        $attempt(fn () => DB::purge('completion_probe'));
        $attempt(fn () => $this->assertProtectionTriggersInstalledExactly());
        $attempt(fn () => parent::tearDown());
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function fixture(string $strategy = 'balance_only', bool $empty = false): array
    {
        $owner = User::factory()->create();
        $this->owners[] = $owner->id;
        $currency = config('accounting.currency');
        $account = DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'name' => 'Reviewed account',
            'type' => 'bank', 'currency' => $currency, 'balance' => $empty ? '0.00' : '100.00']);
        $category = DB::table('categories')->insertGetId(['user_id' => $owner->id, 'name' => 'Reviewed category', 'type' => 'income']);
        $charts = [];
        foreach (['asset', 'equity', 'revenue'] as $type) {
            $charts[$type] = DB::table('chart_of_accounts')->insertGetId(['user_id' => $owner->id,
                'code' => strtoupper($type), 'name' => $type, 'type' => $type, 'cash_role' => 'non_cash', 'is_active' => 1]);
        }
        if (! $empty) {
            DB::table('operational_accounting_account_scope')->insert(['user_id' => $owner->id, 'account_id' => $account]);
            DB::table('operational_accounting_category_scope')->insert(['user_id' => $owner->id, 'category_id' => $category]);
            DB::table('operational_account_mappings')->insert(['user_id' => $owner->id, 'account_id' => $account, 'chart_account_id' => $charts['asset']]);
            DB::table('operational_category_mappings')->insert(['user_id' => $owner->id, 'category_id' => $category,
                'chart_account_id' => $charts['revenue'], 'cash_flow_activity' => 'operating', 'reviewed_by' => $owner->id, 'reviewed_at' => '2026-10-10 09:00:00']);
        }
        $journal = null;
        $assetLine = null;
        $transaction = 0;
        if (! $empty) {
            $journal = DB::table('journal_entries')->insertGetId(['user_id' => $owner->id, 'entry_date' => '2026-10-06', 'currency' => $currency]);
            $assetLine = DB::table('journal_lines')->insertGetId(['user_id' => $owner->id, 'journal_entry_id' => $journal,
                'chart_account_id' => $charts['asset'], 'line_number' => 1, 'debit' => '100.00', 'credit' => '0.00']);
            DB::table('journal_lines')->insert(['user_id' => $owner->id, 'journal_entry_id' => $journal,
                'chart_account_id' => $charts[$strategy === 'historical_backfill' ? 'revenue' : 'equity'],
                'line_number' => 2, 'debit' => '0.00', 'credit' => '100.00']);
            DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => '2026-10-10 09:00:00.000000']);
            if ($strategy === 'historical_backfill') {
                $transaction = DB::table('transactions')->insertGetId(['user_id' => $owner->id, 'account_id' => $account,
                    'category_id' => $category, 'type' => 'income', 'amount' => '100.00', 'date' => '2026-10-06']);
                DB::table('operational_transaction_journals')->insert(['user_id' => $owner->id, 'transaction_id' => $transaction,
                    'journal_entry_id' => $journal, 'account_id' => $account, 'category_id' => $category]);
            }
        }
        $plan = ['historical_transaction_strategy' => $strategy, 'accounting_currency' => $currency,
            'transaction_high_water_id' => $transaction, 'scope_reviewed_at' => '2026-10-10 09:00:00'];
        DB::table('operational_accounting_integrations')->insert($plan + ['user_id' => $owner->id, 'state' => 'reconciling']);
        $reference = (string) Str::uuid();
        $review = DB::table('operational_reconciliation_reviews')->insertGetId($plan + ['user_id' => $owner->id,
            'reference' => $reference, 'created_at' => '2000-01-01 00:00:00.000000',
            'balance_only_disclosure_code' => $strategy === 'balance_only' ? 'balance_only_legacy_history_v1' : null,
            'balance_only_disclosure_text' => $strategy === 'balance_only' ? 'Earlier income statement is incomplete.' : null]);
        $base = ['review_id' => $review, 'user_id' => $owner->id];
        if (! $empty) {
            foreach (['asset', 'revenue'] as $type) {
                DB::table('operational_reconciliation_charts')->insert($base + ['chart_account_id' => $charts[$type],
                    'type_snapshot' => $type, 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1]);
            }
            DB::table('operational_reconciliation_accounts')->insert($base + ['account_id' => $account,
                'chart_account_id' => $charts['asset'], 'stored_balance_snapshot' => '100.00', 'currency_snapshot' => $currency]);
            DB::table('operational_reconciliation_categories')->insert($base + ['category_id' => $category,
                'chart_account_id' => $charts['revenue'], 'category_type_snapshot' => 'income', 'cash_flow_activity' => 'operating']);
            DB::table('operational_reconciliation_journals')->insert($base + ['journal_entry_id' => $journal]);
            DB::table('operational_reconciliation_attributions')->insert($base + ['journal_entry_id' => $journal,
                'journal_line_id' => $assetLine, 'target_kind' => 'account', 'account_id' => $account,
                'purpose' => $strategy === 'historical_backfill' ? 'existing_history' : 'opening_balance', 'amount' => '100.00']);
        }
        $fixture = compact('owner', 'account', 'category', 'charts', 'journal', 'assetLine', 'transaction', 'reference', 'review');
        $fixture['digest'] = $this->digest($fixture);

        return $fixture;
    }

    private function digest(array $f): string
    {
        $review = (array) DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->firstOrFail();
        $evidence = [];
        foreach (['charts', 'accounts', 'categories', 'journals', 'attributions'] as $suffix) {
            $evidence[$suffix] = DB::table('operational_reconciliation_'.$suffix)->where('review_id', $f['review'])
                ->get()->map(fn ($row) => (array) $row)->all();
        }

        return (new OperationalReconciliationValidator)->evidenceDigest($review, $evidence);
    }

    private function complete(array $f, ?CompleteOperationalReconciliation $action = null): array
    {
        return ($action ?? new CompleteOperationalReconciliation)->execute($f['owner'], $f['owner'], $f['reference'], $f['digest']);
    }

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

    private function postingData(array $f): array
    {
        return ['account_id' => $f['account'], 'category_id' => $f['category'], 'type' => 'income', 'amount' => '0.01', 'date' => '2026-10-12'];
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

    private function startWorker(array $f, string $operation = 'complete'): int
    {
        $script = <<<'PHP'
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=mysql_testing');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'mysql_testing', 'database.connections.mysql_testing' => $input['connection']]);
function reportCompletionWorkerEvent(array $data): void { echo json_encode($data, JSON_THROW_ON_ERROR)."\n"; flush(); }
$owner = App\Models\User::query()->findOrFail($input['owner']);
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql): void {
    if ((str_contains($sql, '`users`') && str_contains($sql, 'for update')) || str_starts_with($sql, 'update `operational_reconciliation_accounts`')) { reportCompletionWorkerEvent(['event' => 'sql-start']); }
});
reportCompletionWorkerEvent(['event' => 'ready']);
if (trim(fgets(STDIN)) !== 'start') { throw new RuntimeException('Missing worker start command'); }
try {
    if ($input['operation'] === 'complete') {
        $result = (new App\OperationalAccounting\Actions\CompleteOperationalReconciliation)->execute($owner, $owner, $input['reference'], $input['digest']);
    } elseif ($input['operation'] === 'post') {
        $result = (new App\Actions\PostTransaction)->handle($owner, $input['data'])->id;
    } else {
        $result = Illuminate\Support\Facades\DB::table('operational_reconciliation_accounts')->where('review_id', $input['review'])
            ->where('user_id', $owner->id)->update(['rationale' => 'Concurrent reviewer decision']);
    }
    reportCompletionWorkerEvent(['event' => 'result', 'ok' => true, 'result' => $result]);
} catch (Throwable $e) {
    reportCompletionWorkerEvent(['event' => 'result', 'ok' => false, 'class' => get_class($e), 'reason' => $e instanceof App\Accounting\Exceptions\AccountingConflict ? $e->reason?->name : null,
        'message' => $e->getMessage(), 'code' => $e instanceof Illuminate\Database\QueryException ? ($e->errorInfo[1] ?? null) : null]);
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        $id = count($this->workers);
        $this->workers[$id] = ['process' => $process, 'pipes' => $pipes, 'buffer' => '', 'stderr' => '', 'events' => []];
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fwrite($pipes[0], json_encode(['connection' => config('database.connections.mysql_testing'), 'owner' => $f['owner']->id,
            'reference' => $f['reference'], 'review' => $f['review'], 'digest' => $f['digest'], 'operation' => $operation, 'data' => $this->postingData($f)], JSON_THROW_ON_ERROR)."\n");
        $this->waitEvent($id, 'ready');
        fwrite($pipes[0], "start\n");
        fflush($pipes[0]);
        $this->waitEvent($id, 'sql-start');

        return $id;
    }

    private function drainWorker(int $id, int $microseconds = 20000): void
    {
        $worker = &$this->workers[$id];
        $read = [$worker['pipes'][1], $worker['pipes'][2]];
        $write = null;
        $except = null;
        stream_select($read, $write, $except, 0, $microseconds);
        $worker['buffer'] .= stream_get_contents($worker['pipes'][1]);
        $worker['stderr'] .= stream_get_contents($worker['pipes'][2]);
        while (($end = strpos($worker['buffer'], "\n")) !== false) {
            $line = substr($worker['buffer'], 0, $end);
            $worker['buffer'] = substr($worker['buffer'], $end + 1);
            if ($line !== '') {
                $worker['events'][] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }
    }

    private function waitEvent(int $id, string $event): array
    {
        $deadline = microtime(true) + 15;
        do {
            $this->drainWorker($id);
            foreach ($this->workers[$id]['events'] as $item) {
                if ($item['event'] === $event) {
                    return $item;
                }
            }
            if (! proc_get_status($this->workers[$id]['process'])['running']) {
                break;
            }
        } while (microtime(true) < $deadline);
        $this->fail('Missing worker '.$event.': '.$this->workers[$id]['stderr']);
    }

    private function assertWorkerWaiting(int $id): void
    {
        // SQL-start is explicit. Process liveness and no completion during a
        // bounded coordination window prove it did not bypass the held lock.
        $deadline = microtime(true) + 0.2;
        do {
            $this->drainWorker($id);
            $this->assertTrue(proc_get_status($this->workers[$id]['process'])['running']);
            $this->assertNotContains('result', array_column($this->workers[$id]['events'], 'event'));
        } while (microtime(true) < $deadline);
    }

    public function test_two_connection_create_first_completion_reads_committed_boundary_staleness(): void
    {
        $f = $this->fixture('balance_only', true);
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
            $first = $this->startWorker($f);
            $second = $this->startWorker($f);
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
        config(['database.connections.completion_probe' => config('database.connections.mysql_testing')]);
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
            $job = $this->startWorker($f);
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
            $job = $this->startWorker($f);
            $result = $this->waitEvent($job, 'result');
        } finally {
            DB::commit();
        }
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $foreign['review'], 'completed_at' => null]);
    }

    private function allFixtureRows(int $owner): array
    {
        $result = [];
        foreach ($this->fixtureTables() as $table) {
            $result[$table] = DB::table($table)->where('user_id', $owner)->get()->map(fn ($r) => (array) $r)->all();
        }

        return $result;
    }

    private function fixtureTables(): array
    {
        return ['operational_accounting_integrations', 'operational_reconciliation_attributions', 'operational_reconciliation_journals',
            'operational_reconciliation_categories', 'operational_reconciliation_accounts', 'operational_reconciliation_charts',
            'operational_reconciliation_reviews', 'operational_transaction_journals', 'journal_line_allocations', 'cash_flow_journal_completions',
            'fiscal_year_closes', 'opening_balance_lines', 'opening_balance_batches', 'journal_lines', 'journal_entries',
            'operational_accounting_account_scope', 'operational_accounting_category_scope', 'operational_account_mappings',
            'operational_category_mappings', 'transactions', 'accounts', 'categories', 'chart_of_accounts', 'accounting_periods'];
    }

    private function cleanupFixtures(): void
    {
        // Test-owned IDs only. Restore every deletion guard even if cleanup fails;
        // no FK disabling, truncation, migration changes or unknown row deletion.
        $guards = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('EVENT_OBJECT_TABLE', $this->fixtureTables())->where('EVENT_MANIPULATION', 'DELETE')->get();
        try {
            foreach ($guards as $guard) {
                DB::unprepared('DROP TRIGGER `'.$guard->TRIGGER_NAME.'`');
            }
            foreach ($this->fixtureTables() as $table) {
                $query = DB::table($table)->whereIn('user_id', $this->owners);
                if (in_array($table, ['operational_reconciliation_reviews', 'journal_entries'], true)) {
                    $query->orderByDesc('id');
                }
                $query->delete();
            }
            DB::table('users')->whereIn('id', $this->owners)->delete();
        } finally {
            $restoreFailure = null;
            foreach ($guards as $guard) {
                try {
                    if (! DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $guard->TRIGGER_NAME)->exists()) {
                        DB::unprepared('CREATE TRIGGER `'.$guard->TRIGGER_NAME.'` '.$guard->ACTION_TIMING.' DELETE ON `'.$guard->EVENT_OBJECT_TABLE.'` FOR EACH ROW '.$guard->ACTION_STATEMENT);
                    }
                } catch (Throwable $exception) {
                    $restoreFailure ??= $exception;
                }
            }
            if ($restoreFailure !== null) {
                throw $restoreFailure;
            }
        }
        foreach ($this->fixtureTables() as $table) {
            $this->assertFalse(DB::table($table)->whereIn('user_id', $this->owners)->exists(), $table);
        }
    }
}
