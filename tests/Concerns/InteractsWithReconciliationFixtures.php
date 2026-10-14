<?php

namespace Tests\Concerns;

use App\Models\User;
use App\OperationalAccounting\Actions\CompleteOperationalReconciliation;
use App\OperationalAccounting\OperationalReconciliationValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Dedicated full accounting fixtures; structural protection tests remain unchanged. */
trait InteractsWithReconciliationFixtures
{
    use InteractsWithEvidenceProtectionTriggers;

    private array $owners = [];

    private array $workers = [];

    private array $probeConnections = [];

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
        foreach ($this->probeConnections as $name) {
            $attempt(fn () => DB::purge($name));
        }
        $attempt(fn () => $this->assertProtectionTriggersInstalledExactly());
        $attempt(fn () => parent::tearDown());
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function configureProbeConnection(string $name): void
    {
        config(["database.connections.{$name}" => config('database.connections.mysql_testing')]);
        $this->probeConnections[] = $name;
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

    private function postingData(array $f): array
    {
        return ['account_id' => $f['account'], 'category_id' => $f['category'], 'type' => 'income', 'amount' => '0.01', 'date' => '2026-10-12'];
    }

    private function startWorker(array $f, string $operation): int
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
    if ($input['operation'] === 'ready') {
        $result = (new App\OperationalAccounting\Actions\MarkOperationalAccountingReady)->execute($owner);
    } elseif ($input['operation'] === 'complete') {
        $result = (new App\OperationalAccounting\Actions\CompleteOperationalReconciliation)->execute($owner, $owner, $input['reference'], $input['digest']);
    } elseif ($input['operation'] === 'post') {
        $result = (new App\Actions\PostTransaction)->handle($owner, $input['data'])->id;
    } elseif ($input['operation'] === 'map-account') {
        $result = (new App\OperationalAccounting\Actions\MapOperationalAccount)->execute($owner, $input['data']['account_id'], $input['mapping_target']);
    } elseif ($input['operation'] === 'deactivate-chart') {
        $chart = App\Models\ChartAccount::ownedBy($owner)->findOrFail($input['chart_target']);
        $result = (new App\Accounting\Actions\UpdateChartAccount)->execute($owner, $chart->id, $chart->code, $chart->name, $chart->type, false, $chart->parent_id)->id;
    } elseif ($input['operation'] === 'post-journal') {
        $result = (new App\Accounting\Actions\PostJournalEntry)->execute($owner, $input['history_journal'])->id;
    } elseif ($input['operation'] === 'link-operational-journal') {
        // Isolated fixture mutation only, not an integrated posting action.
        $result = Illuminate\Support\Facades\DB::transaction(function () use ($owner, $input) {
            App\Accounting\AccountingPeriodLocks::owner($owner);
            (new App\Accounting\PeriodGuard)->assertOpen($owner, '2026-10-06');
            $db = Illuminate\Support\Facades\DB::connection();
            $db->table('fiscal_year_closes')->where('user_id', $owner->id)->where('journal_entry_id', $input['history_journal'])->lockForUpdate()->first(['id']);
            $db->table('accounts')->where('user_id', $owner->id)->where('id', $input['data']['account_id'])->lockForUpdate()->first();
            $db->table('categories')->where('user_id', $owner->id)->where('id', $input['data']['category_id'])->lockForUpdate()->first();
            $db->table('operational_accounting_integrations')->where('user_id', $owner->id)->lockForUpdate()->first();
            $targets = (new App\OperationalAccounting\OperationalReconciliationChartDependencies)->lockParticipation($owner->id);
            $db->table('chart_of_accounts')->where('user_id', $owner->id)->whereIn('id', array_keys($targets))->orderBy('id')->lockForUpdate()->get();
            $db->table('transactions')->where('user_id', $owner->id)->where('id', $input['transaction_id'])->lockForUpdate()->first();
            $db->table('journal_entries')->where('user_id', $owner->id)->where('id', $input['history_journal'])->lockForUpdate()->first();
            $db->table('journal_lines')->where('user_id', $owner->id)->where('journal_entry_id', $input['history_journal'])->orderBy('id')->lockForUpdate()->get();
            $db->table('journal_line_allocations')->where('user_id', $owner->id)->where('journal_entry_id', $input['history_journal'])->orderBy('id')->sharedLock()->get();
            $db->table('cash_flow_journal_completions')->where('user_id', $owner->id)->where('journal_entry_id', $input['history_journal'])->sharedLock()->first();
            $db->table('opening_balance_batches')->where('user_id', $owner->id)->where('journal_entry_id', $input['history_journal'])->sharedLock()->first();
            $db->table('operational_transaction_journals')->where('user_id', $owner->id)->where('journal_entry_id', $input['history_journal'])->sharedLock()->first();
            $db->table('operational_transaction_journals')->insert(['user_id' => $owner->id, 'transaction_id' => $input['transaction_id'],
                'journal_entry_id' => $input['history_journal'], 'account_id' => $input['data']['account_id'], 'category_id' => $input['data']['category_id']]);
            return $input['history_journal'];
        });
    } elseif ($input['operation'] === 'reverse-journal') {
        $result = (new App\Accounting\Actions\ReverseJournalEntry)->execute($owner, $input['history_journal'])->id;
    } elseif ($input['operation'] === 'post-opening') {
        $result = (new App\Accounting\Actions\PostOpeningBalanceBatch)->execute($owner, $input['opening_batch'])->id;
    } elseif ($input['operation'] === 'historical-cashflow') {
        $result = (new App\Accounting\Actions\CompleteHistoricalCashFlowJournal)->execute($owner, $input['history_journal'], $input['history_allocations'])->name;
    } elseif ($input['operation'] === 'close-fiscal') {
        $result = (new App\Accounting\Actions\CloseFiscalYear)->execute($owner, $input['fiscal_start'], $input['fiscal_end'], config('accounting.currency'), $input['retained_account'])->id;
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
            'reference' => $f['reference'], 'review' => $f['review'], 'digest' => $f['digest'], 'operation' => $operation,
            'transaction_id' => $f['transaction'] ?? null, 'mapping_target' => $f['mapping_target'] ?? null, 'chart_target' => $f['chart_target'] ?? null, 'history_journal' => $f['history_journal'] ?? null,
            'opening_batch' => $f['opening_batch'] ?? null, 'history_allocations' => $f['history_allocations'] ?? [],
            'fiscal_start' => $f['fiscal_start'] ?? null, 'fiscal_end' => $f['fiscal_end'] ?? null, 'retained_account' => $f['retained_account'] ?? null,
            'data' => $this->postingData($f)], JSON_THROW_ON_ERROR)."\n");
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

    private function boundFixture(string $strategy = 'balance_only', bool $empty = false): array
    {
        $fixture = $this->fixture($strategy, $empty);
        $this->complete($fixture);

        return $fixture;
    }
}
