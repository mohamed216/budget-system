<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithEvidenceProtectionTriggers;
use Tests\TestCase;
use Throwable;

class OperationalReconciliationEvidenceProtectionMigrationTest extends TestCase
{
    use InteractsWithEvidenceProtectionTriggers;

    private ?Connection $evidenceProbe = null;

    private array $fixtures = [];

    private array $jobs = [];

    private array $fixtureReviewIds = [];

    private array $fixtureReferences = [];

    private array $fixtureOwnerIds = [];

    private array $fixtureSources = [];

    private array $schemaRestorations = [];

    private array $temporaryTriggerNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertProtectionTriggersInstalledExactly();
        foreach (['reviews', 'charts', 'accounts', 'categories', 'journals', 'attributions'] as $suffix) {
            $this->assertDatabaseCount('operational_reconciliation_'.$suffix, 0);
        }
    }

    protected function tearDown(): void
    {
        $failure = null;
        $attempt = function (callable $operation) use (&$failure): void {
            try {
                $operation();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        };
        try {
            $attempt(fn () => $this->stopJobs());
            $attempt(fn () => $this->rollbackConnections());
            foreach (array_reverse($this->schemaRestorations) as $restore) {
                $attempt($restore);
            }
            foreach ($this->temporaryTriggerNames as $name) {
                $attempt(fn () => DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`"));
            }
            // Restore the COMPLETE set even with no fixtures, or after partial
            // installation/drop/alter failures. Never rely on one sentinel name.
            $attempt(fn () => $this->restoreProtectionTriggers());
            $attempt(fn () => $this->discoverCreatedReviews());
            foreach (array_keys($this->fixtureOwnerIds) as $owner) {
                $attempt(fn () => $this->cleanup(['owner' => $owner]));
            }
            $attempt(function (): void {
                foreach (array_keys($this->fixtureReviewIds) as $id) {
                    $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $id]);
                }
            });
            $attempt(fn () => $this->assertProtectionTriggersInstalledExactly());
        } finally {
            DB::purge('evidence_probe');
            $this->evidenceProbe = null;
            config(['database.connections.evidence_probe' => null]);
            $attempt(fn () => parent::tearDown());
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php');
    }

    private function definitions(): array
    {
        $migration = $this->migration();

        return (new \ReflectionMethod($migration, 'definitions'))->invoke($migration);
    }

    private function triggers(): array
    {
        return DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', array_keys($this->definitions()))->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all();
    }

    private function refuses(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Unsafe evidence protection migration was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function rejects(callable $operation, int $code, string $message): void
    {
        try {
            $operation();
            $this->fail('Evidence concurrency guard was bypassed.');
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[1]);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create()->id;
        $this->fixtureOwnerIds[$owner] = true;
        $reference = (string) Str::uuid();
        $this->fixtureReferences[$reference] = $owner;
        $review = DB::table('operational_reconciliation_reviews')->insertGetId([
            'user_id' => $owner, 'reference' => $reference,
            'historical_transaction_strategy' => 'historical_backfill', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 0, 'scope_reviewed_at' => '2026-10-12 00:00:00',
            'created_at' => '2026-10-12 00:00:00',
        ]);
        $this->fixtureReviewIds[$review] = $owner;
        $this->fixtures[$review] = ['owner' => $owner, 'review' => $review];
        $chart = DB::table('chart_of_accounts')->insertGetId([
            'user_id' => $owner, 'code' => '1000', 'name' => 'Concurrency fixture', 'type' => 'asset', 'cash_role' => 'non_cash',
        ]);
        $this->fixtureSources[$owner]['chart_of_accounts'][] = $chart;
        $row = ['review_id' => $review, 'user_id' => $owner, 'chart_account_id' => $chart,
            'type_snapshot' => 'asset', 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1];

        $fixture = compact('owner', 'review', 'chart', 'row');
        $this->fixtures[$review] = $fixture;

        return $fixture;
    }

    private function probe(): \Illuminate\Database\Connection
    {
        config(['database.connections.evidence_probe' => config('database.connections.mysql_testing')]);
        DB::purge('evidence_probe');
        $probe = $this->evidenceProbe = DB::connection('evidence_probe');
        $this->assertNotSame(DB::connection()->getPdo(), $probe->getPdo());
        $probe->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        return $probe;
    }

    private function seal(\Illuminate\Database\Connection $connection, array $f): void
    {
        $connection->table('operational_reconciliation_reviews')->where('id', $f['review'])->update([
            'reviewed_by' => $f['owner'], 'completed_at' => '2026-10-13 00:00:00.123456',
        ]);
    }

    private function rollbackConnections(): void
    {
        foreach (array_filter([DB::connection(), $this->evidenceProbe]) as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        DB::purge('evidence_probe');
        $this->evidenceProbe = null;
        config(['database.connections.evidence_probe' => null]);
    }

    private function discoverCreatedReviews(): void
    {
        // The subprocess may commit then die before sending its insert ID.
        // Recover only this test's pre-registered UUIDs, never owner-wide rows.
        foreach ($this->fixtureReferences as $reference => $owner) {
            $id = DB::table('operational_reconciliation_reviews')->where('user_id', $owner)
                ->where('reference', $reference)->value('id');
            if ($id !== null) {
                $this->fixtureReviewIds[(int) $id] = $owner;
            }
        }
    }

    private function cleanup(array $f): void
    {
        $this->stopJobs();
        $this->rollbackConnections();
        $this->discoverCreatedReviews();
        $reviewIds = array_keys(array_filter($this->fixtureReviewIds, fn ($owner) => $owner === $f['owner']));
        rsort($reviewIds);
        try {
            // A previous assertion/SQL failure may have left ANY subset absent.
            $this->restoreProtectionTriggers();
            $sealed = DB::table('operational_reconciliation_reviews')->whereIn('id', $reviewIds)
                ->whereNotNull('completed_at')->exists();
            if ($sealed) {
                // Test-only teardown of tracked committed seals. Keep ALL FKs.
                // Finally restores the whole set, not only successfully dropped names.
                foreach ($this->definitions() as $name => $definition) {
                    if ($definition[1] === 'DELETE') {
                        DB::unprepared("DROP TRIGGER `{$name}`");
                    }
                }
            }
            foreach (['attributions', 'journals', 'categories', 'accounts', 'charts'] as $suffix) {
                DB::table('operational_reconciliation_'.$suffix)
                    ->whereIn('review_id', $reviewIds)->where('user_id', $f['owner'])->delete();
            }
            foreach ($reviewIds as $id) {
                DB::table('operational_reconciliation_reviews')->where('id', $id)->where('user_id', $f['owner'])->delete();
            }
        } finally {
            $this->restoreProtectionTriggers();
        }
        foreach ($reviewIds as $id) {
            $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $id]);
            unset($this->fixtures[$id]);
        }
        foreach (['journal_entries', 'accounts', 'categories', 'chart_of_accounts'] as $table) {
            DB::table($table)->whereIn('id', $this->fixtureSources[$f['owner']][$table] ?? [])
                ->where('user_id', $f['owner'])->delete();
        }
        DB::table('users')->where('id', $f['owner'])->delete();
        unset($this->fixtureOwnerIds[$f['owner']]);
        $this->assertProtectionTriggersInstalledExactly();
    }

    public function test_cleanup_without_probe_removes_only_its_own_evidence(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        try {
            DB::table('operational_reconciliation_charts')->insert($first['row']);
            $this->assertNull(config('database.connections.evidence_probe'));
            $this->cleanup($first);
            $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $first['review']]);
            $this->assertDatabaseMissing('operational_reconciliation_charts', ['review_id' => $first['review']]);
            $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $second['review']]);
            $this->assertCount(18, $this->triggers());
        } finally {
            if (isset($this->fixtures[$first['review']])) {
                $this->cleanup($first);
            }
            $this->cleanup($second);
        }
    }

    public function test_partial_trigger_restoration_survives_an_assertion_failure_with_sealed_fixture(): void
    {
        $f = $this->fixture();
        try {
            $this->seal(DB::connection(), $f);
            try {
                DB::unprepared('DROP TRIGGER `orr_header_bd`');
                DB::unprepared('DROP TRIGGER `orr_charts_bd`');
                $this->fail('Injected assertion after a partial trigger drop.');
            } catch (\PHPUnit\Framework\AssertionFailedError $exception) {
                $this->assertStringContainsString('Injected assertion', $exception->getMessage());
            } finally {
                $this->restoreProtectionTriggers();
            }
            $this->assertProtectionTriggersInstalledExactly();
        } finally {
            $this->cleanup($f);
        }
        $this->assertProtectionTriggersInstalledExactly();
        $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $f['review']]);
    }

    public function test_cleanup_recovers_only_a_registered_reference_without_an_insert_result(): void
    {
        $f = $this->fixture();
        $reference = (string) Str::uuid();
        $this->fixtureReferences[$reference] = $f['owner'];
        try {
            // Simulate a worker committing then dying before its result reaches
            // the parent. Deliberately do not retrieve/register lastInsertId.
            DB::table('operational_reconciliation_reviews')->insert([
                'user_id' => $f['owner'], 'reference' => $reference,
                'historical_transaction_strategy' => 'historical_backfill', 'accounting_currency' => 'SAR',
                'transaction_high_water_id' => 0, 'scope_reviewed_at' => '2026-10-12 00:00:00',
            ]);
        } finally {
            $this->cleanup($f);
        }
        $this->assertCount(2, $this->fixtureReviewIds);
        foreach (array_keys($this->fixtureReviewIds) as $id) {
            $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $id]);
        }
    }

    public function test_empty_rollback_reinstall_is_exact_and_creates_no_evidence(): void
    {
        $before = [];
        foreach (['accounts', 'categories', 'transactions', 'chart_of_accounts', 'journal_entries', 'journal_lines',
            'operational_accounting_integrations', 'operational_account_mappings', 'operational_category_mappings'] as $table) {
            $before[$table] = DB::table($table)->get()->all();
        }
        $expected = array_keys($this->definitions());
        sort($expected);
        $this->assertSame($expected, $this->triggers());
        $this->migration()->down();
        $this->assertSame([], $this->triggers());
        try {
            $this->migration()->up();
            $this->assertSame($expected, $this->triggers());
            foreach (['reviews', 'charts', 'accounts', 'categories', 'journals', 'attributions'] as $suffix) {
                $this->assertDatabaseCount('operational_reconciliation_'.$suffix, 0);
            }
            foreach ($before as $table => $rows) {
                $this->assertEquals($rows, DB::table($table)->get()->all(), $table);
            }
        } finally {
            $this->restoreProtectionTriggers();
            $this->assertProtectionTriggersInstalledExactly();
        }
    }

    public function test_populated_rollback_refuses_without_removing_any_trigger(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->triggers();
            $this->refuses(fn () => $this->migration()->down(), 'contains rows');
            $this->assertSame($before, $this->triggers());
            $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $f['review']]);
        } finally {
            $this->cleanup($f);
        }
        $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $f['review']]);
        $this->assertCount(18, $this->triggers());
    }

    public function test_installation_does_not_adopt_existing_names_or_unprotected_evidence(): void
    {
        $this->refuses(fn () => $this->migration()->up(), 'already exist');
        $this->migration()->down();
        $f = $this->fixture();
        try {
            $before = (array) DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->first();
            $this->refuses(fn () => $this->migration()->up(), 'nonempty unprotected');
            $this->assertSame([], $this->triggers());
            $this->assertSame($before, (array) DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->first());
        } finally {
            try {
                $this->cleanup($f);
            } finally {
                $this->restoreProtectionTriggers();
            }
        }
        $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $f['review']]);
        $this->assertCount(18, $this->triggers());
    }

    public function test_unknown_and_altered_triggers_fail_closed_before_any_drop(): void
    {
        $this->temporaryTriggerNames[] = 'evidence_unknown_probe';
        DB::unprepared("CREATE TRIGGER `evidence_unknown_probe` BEFORE DELETE ON `operational_reconciliation_reviews` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown guard'");
        try {
            $this->refuses(fn () => $this->migration()->up(), 'Unexpected evidence trigger');
            $this->refuses(fn () => $this->migration()->down(), 'Unexpected evidence trigger');
            $this->assertCount(18, $this->triggers());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS `evidence_unknown_probe`');
            $this->restoreProtectionTriggers();
            $this->assertProtectionTriggersInstalledExactly();
        }
        $definition = $this->definitions()['orr_header_bd'];
        DB::unprepared('DROP TRIGGER `orr_header_bd`');
        DB::unprepared("CREATE TRIGGER `orr_header_bd` BEFORE DELETE ON `operational_reconciliation_reviews` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Altered guard'");
        try {
            $this->refuses(fn () => $this->migration()->down(), 'Unexpected evidence trigger');
            $this->assertCount(18, $this->triggers());
        } finally {
            $this->restoreProtectionTriggers();
            $this->assertProtectionTriggersInstalledExactly();
        }
    }

    public function test_incompatible_foundation_fails_before_creating_protections(): void
    {
        $this->migration()->down();
        $restore = function (): void {
            if (Schema::hasColumn('operational_reconciliation_reviews', 'unexpected_probe')) {
                DB::statement('ALTER TABLE operational_reconciliation_reviews DROP COLUMN unexpected_probe');
            }
        };
        $this->schemaRestorations[] = $restore;
        DB::statement('ALTER TABLE operational_reconciliation_reviews ADD COLUMN unexpected_probe INT NULL');
        try {
            $this->refuses(fn () => $this->migration()->up(), 'Unexpected evidence columns');
            $this->assertSame([], $this->triggers());
        } finally {
            try {
                $restore();
            } finally {
                $this->restoreProtectionTriggers();
            }
            $this->assertProtectionTriggersInstalledExactly();
        }
    }

    public static function alteredFoundationChecks(): array
    {
        return [['orat_amount_check'], ['orat_target_check']];
    }

    #[DataProvider('alteredFoundationChecks')]
    public function test_altered_enforced_check_rejects_installation_before_creating_triggers(string $name): void
    {
        $this->migration()->down();
        $migration = $this->migration();
        $expressions = (new \ReflectionMethod($migration, 'checkExpressions'))->invoke($migration);
        $restore = function () use ($name, $expressions): void {
            // Use valid foundation source SQL, never information_schema's
            // escaped literal serialization as DDL input.
            $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'operational_reconciliation_attributions')
                ->where('CONSTRAINT_NAME', $name)->exists();
            if ($exists) {
                DB::statement("ALTER TABLE operational_reconciliation_attributions DROP CHECK `{$name}`");
            }
            DB::statement("ALTER TABLE operational_reconciliation_attributions ADD CONSTRAINT `{$name}` CHECK ({$expressions[$name]}) ENFORCED");
        };
        $this->schemaRestorations[] = $restore;
        try {
            $alteredExpression = $name === 'orat_amount_check' ? 'amount >= 0' : '1 = 1';
            DB::statement("ALTER TABLE operational_reconciliation_attributions DROP CHECK `{$name}`");
            DB::statement("ALTER TABLE operational_reconciliation_attributions ADD CONSTRAINT `{$name}` CHECK ({$alteredExpression}) ENFORCED");
            $this->assertSame('YES', DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'operational_reconciliation_attributions')
                ->where('CONSTRAINT_NAME', $name)->value('ENFORCED'));
            $this->refuses(fn () => $migration->up(), "Unexpected evidence CHECK definition {$name}");
            $this->assertSame([], $this->triggers());
        } finally {
            try {
                $restore();
            } finally {
                $this->restoreProtectionTriggers();
            }
            $this->assertProtectionTriggersInstalledExactly();
        }
    }

    public function test_actual_foundation_target_key_metadata_is_accepted(): void
    {
        $key = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'operational_reconciliation_attributions')->where('COLUMN_NAME', 'target_key')->first();
        $context = json_encode((array) $key, JSON_UNESCAPED_SLASHES);
        $this->assertNotNull($key);
        $this->assertSame('varchar', $key->DATA_TYPE, $context);
        $this->assertSame('varchar(37)', $key->COLUMN_TYPE, $context);
        $this->assertSame('ascii', $key->CHARACTER_SET_NAME, $context);
        $this->assertSame('ascii_bin', $key->COLLATION_NAME, $context);
        $this->assertSame('STORED GENERATED', $key->EXTRA, $context);
        $this->assertNotEmpty($key->GENERATION_EXPRESSION, $context);
        $migration = $this->migration();
        try {
            (new \ReflectionMethod($migration, 'requireTargetKey'))->invoke($migration, $key);
        } catch (\RuntimeException $exception) {
            $this->fail($exception->getMessage().' Metadata: '.$context);
        }
        $this->assertCount(18, $this->triggers());
    }

    public function test_different_stored_target_key_expression_rejects_installation(): void
    {
        $this->migration()->down();
        $restore = function (): void {
            // Valid source SQL from 000014, NOT escaped metadata serialization.
            DB::statement(<<<'SQL'
ALTER TABLE operational_reconciliation_attributions MODIFY COLUMN target_key
    VARCHAR(37) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (
        CASE WHEN account_id IS NULL THEN 'excluded'
        ELSE CONCAT(CAST(account_id AS CHAR), ':', purpose) END
    ) STORED
SQL);
        };
        $this->schemaRestorations[] = $restore;
        try {
            // Alter only the disposable test table, never foundation migration 000014.
            DB::statement(<<<'SQL'
ALTER TABLE operational_reconciliation_attributions MODIFY COLUMN target_key
    VARCHAR(37) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (
        CASE WHEN account_id IS NULL THEN 'different'
        ELSE CONCAT(CAST(account_id AS CHAR), ':', purpose) END
    ) STORED
SQL);
            $this->refuses(fn () => $this->migration()->up(), 'Unexpected attribution generated key');
            $this->assertSame([], $this->triggers());
        } finally {
            try {
                $restore();
            } finally {
                $this->restoreProtectionTriggers();
            }
            $this->assertProtectionTriggersInstalledExactly();
        }
    }

    private function startJob(string $sql, array $bindings, array $prelocks = []): array
    {
        $reference = null;
        $reviewOwner = null;
        if (preg_match('/^\s*INSERT INTO operational_reconciliation_reviews\b/i', $sql)) {
            [$reviewOwner, $reference] = $bindings;
            $this->assertArrayHasKey($reviewOwner, $this->fixtureOwnerIds);
            // Register before launching: recover a committed insert even if the
            // worker dies before stdout/result parsing can return its numeric ID.
            $this->fixtureReferences[$reference] = $reviewOwner;
        }
        $script = <<<'PHP'
function reportEvent(array $event): void {
    echo json_encode($event, JSON_THROW_ON_ERROR)."\n";
    flush();
}
function executeStatement(PDO $pdo, string $sql, array $bindings, string $phase): PDOStatement {
    $statement = $pdo->prepare($sql);
    reportEvent(['event' => 'sql_started', 'phase' => $phase]);
    try {
        $statement->execute($bindings);
        return $statement;
    } finally {
        reportEvent(['event' => 'sql_finished', 'phase' => $phase]);
    }
}
try {
    $input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $c = $input['connection'];
    $dsn = empty($c['unix_socket'])
        ? "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4"
        : "mysql:unix_socket={$c['unix_socket']};dbname={$c['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    reportEvent(['event' => 'ready', 'connection_id' => $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]);
    $command = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    if (($command['command'] ?? null) !== 'start') {
        throw new RuntimeException('Expected the parent start command.');
    }
    $prelockRows = [];
    foreach ($input['prelocks'] as $index => [$sql, $bindings]) {
        $s = executeStatement($pdo, $sql, $bindings, 'prelock_'.$index);
        $prelockRows[] = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    executeStatement($pdo, $input['sql'], $input['bindings'], 'mutation');
    $result = ['ok' => true, 'id' => $pdo->lastInsertId(), 'prelock_rows' => $prelockRows];
    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $result = ['ok' => false, 'code' => $e instanceof PDOException ? ($e->errorInfo[1] ?? null) : null,
        'message' => $e->getMessage()];
}
reportEvent(['event' => 'result', 'result' => $result]);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, base_path());
        $this->assertIsResource($process);
        $index = count($this->jobs);
        $this->jobs[$index] = ['process' => $process, 'pipes' => $pipes, 'buffer' => '', 'stderr' => '',
            'connection_id' => null, 'active' => null, 'result' => null, 'finished' => [],
            'waiting_phase' => $prelocks === [] ? 'mutation' : 'prelock_'.(count($prelocks) - 1),
            'reference' => $reference, 'review_owner' => $reviewOwner];
        // Nonblocking drains handle partial JSON lines and keep stderr from
        // filling a pipe. A boot-ready event is NOT evidence of executing SQL.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        try {
            $payload = json_encode(['connection' => config('database.connections.mysql_testing'),
                'sql' => $sql, 'bindings' => $bindings, 'prelocks' => $prelocks], JSON_THROW_ON_ERROR)."\n";
            $written = 0;
            while ($written < strlen($payload)) {
                $count = fwrite($pipes[0], substr($payload, $written));
                if ($count === false || $count === 0) {
                    throw new \RuntimeException('Could not send the isolated worker its fixture.');
                }
                $written += $count;
            }
        } catch (Throwable $exception) {
            fclose($pipes[0]);
            throw $exception;
        }
        $deadline = microtime(true) + 10;
        do {
            $this->drainJob($index);
            if ($this->jobs[$index]['connection_id'] !== null) {
                return ['index' => $index, 'connection_id' => $this->jobs[$index]['connection_id']];
            }
            $this->assertNull($this->jobs[$index]['result'], 'Worker failed before reporting connection readiness.');
            $this->waitForJobOutput($index);
        } while (microtime(true) < $deadline);
        $this->fail('Second connection did not start.');
    }

    private function drainJob(int $index): void
    {
        $job = &$this->jobs[$index];
        $job['buffer'] .= stream_get_contents($job['pipes'][1]);
        $job['stderr'] .= stream_get_contents($job['pipes'][2]);
        while (($newline = strpos($job['buffer'], "\n")) !== false) {
            $line = substr($job['buffer'], 0, $newline);
            $job['buffer'] = substr($job['buffer'], $newline + 1);
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            switch ($event['event']) {
                case 'ready':
                    $job['connection_id'] = (int) $event['connection_id'];
                    break;
                case 'sql_started':
                    $job['active'] = $event;
                    break;
                case 'sql_finished':
                    $job['finished'][$event['phase']] = true;
                    $job['active'] = null;
                    break;
                case 'result':
                    $job['result'] = $event['result'];
                    if ($job['result']['ok'] && $job['reference'] !== null) {
                        $this->fixtureReviewIds[(int) $job['result']['id']] = $job['review_owner'];
                    }
                    break;
                default:
                    throw new \RuntimeException('Unknown isolated worker event.');
            }
        }
    }

    private function waitForJobOutput(int $index): void
    {
        $read = [$this->jobs[$index]['pipes'][1], $this->jobs[$index]['pipes'][2]];
        $write = $except = null;
        // A bounded protocol-coordination wait, not a database lock timeout.
        if (stream_select($read, $write, $except, 0, 50000) === false) {
            throw new \RuntimeException('Could not watch isolated worker output.');
        }
    }

    private function assertJobWaiting(array $job): void
    {
        $index = $job['index'];
        // startJob returns only after ready. No fixture SQL can run until this
        // command, and cleanup closes stdin even if the handshake fails.
        $pipe = $this->jobs[$index]['pipes'][0];
        try {
            $command = "{\"command\":\"start\"}\n";
            $this->assertSame(strlen($command), fwrite($pipe, $command));
        } finally {
            fclose($pipe);
        }
        $phase = $this->jobs[$index]['waiting_phase'];
        $observedAt = null;
        $deadline = microtime(true) + 5;
        do {
            $this->drainJob($index);
            $this->assertNull($this->jobs[$index]['result'],
                'SQL completed instead of waiting: '.json_encode($this->jobs[$index]['result']));
            $this->assertTrue(proc_get_status($this->jobs[$index]['process'])['running'],
                'Worker exited while its controlling lock was held. '.$this->jobs[$index]['stderr']);
            $this->assertArrayNotHasKey($phase, $this->jobs[$index]['finished'],
                'Expected SQL finished before its controlling lock was released.');
            if (($this->jobs[$index]['active']['phase'] ?? null) === $phase) {
                $observedAt ??= microtime(true);
                if (microtime(true) - $observedAt >= 0.2) {
                    // Explicit SQL-start for the expected phase, a live worker,
                    // and no finish/result throughout a bounded event window.
                    // Tests hold the intended lock and check exact outcomes after
                    // release; processlist SQL serialization is not authoritative.
                    return;
                }
            }
            $this->waitForJobOutput($index);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not enter its expected waiting SQL statement.');
    }

    private function readJobResult(array $job): array
    {
        $deadline = microtime(true) + 10;
        do {
            $this->drainJob($job['index']);
            if ($this->jobs[$job['index']]['result'] !== null) {
                $this->assertArrayHasKey($this->jobs[$job['index']]['waiting_phase'],
                    $this->jobs[$job['index']]['finished'], 'Worker did not finish the previously blocked SQL.');

                return $this->jobs[$job['index']]['result'];
            }
            $this->waitForJobOutput($job['index']);
        } while (microtime(true) < $deadline);
        $this->fail('Second connection did not finish after release.');
    }

    private function stopJobs(): void
    {
        $failure = null;
        foreach (array_keys($this->jobs) as $index) {
            $job = $this->jobs[$index];
            try {
                if (proc_get_status($job['process'])['running']) {
                    proc_terminate($job['process']);
                    $deadline = microtime(true) + 1;
                    do {
                        usleep(10000);
                        $this->drainJob($index);
                    } while (proc_get_status($job['process'])['running'] && microtime(true) < $deadline);
                    if (proc_get_status($job['process'])['running']) {
                        proc_terminate($job['process'], 9);
                    }
                }
                $this->drainJob($index);
            } catch (Throwable $exception) {
                $failure ??= $exception;
                proc_terminate($job['process'], 9);
            } finally {
                foreach ($job['pipes'] as $number => $pipe) {
                    if (is_resource($pipe)) {
                        if ($number !== 0) {
                            stream_get_contents($pipe);
                        }
                        fclose($pipe);
                    }
                }
                proc_close($job['process']);
            }
        }
        $this->jobs = [];
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function test_child_first_holds_parent_shared_lock_until_commit_before_seal(): void
    {
        $f = $this->fixture();
        try {
            DB::beginTransaction();
            DB::table('operational_reconciliation_charts')->insert($f['row']);
            // Source-first application-style seal: shared chart source is
            // compatible with the child's shared lock, parent X must wait.
            $job = $this->startJob(
                'UPDATE operational_reconciliation_reviews SET reviewed_by = ?, completed_at = ? WHERE id = ?',
                [$f['owner'], '2026-10-13 00:00:00.123456', $f['review']],
                [['SELECT id FROM users WHERE id = ? FOR SHARE', [$f['owner']]],
                    ['SELECT id FROM chart_of_accounts WHERE id = ? FOR SHARE', [$f['chart']]],
                    ['SELECT chart_account_id FROM operational_reconciliation_charts WHERE review_id = ? ORDER BY chart_account_id FOR SHARE', [$f['review']]]]
            );
            $this->assertJobWaiting($job);
            DB::commit();
            $result = $this->readJobResult($job);
            $this->assertTrue($result['ok']);
            $this->assertSame([$f['chart']], array_map('intval', array_column($result['prelock_rows'][2], 'chart_account_id')));
            // Current read after the child commits includes its evidence.
            $this->assertDatabaseHas('operational_reconciliation_charts', $f['row']);
            $this->assertNotNull(DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->value('completed_at'));
        } finally {
            $this->stopJobs();
            $this->cleanup($f);
        }
    }

    public function test_child_holds_parent_shared_current_lock_until_commit(): void
    {
        $f = $this->fixture();
        try {
            $probe = $this->probe();
            DB::beginTransaction();
            DB::table('operational_reconciliation_charts')->insert($f['row']);
            // Standalone NOWAIT is only a diagnostic for the child's parent S
            // lock, never a claim about nonwaiting trigger or implicit FK reads.
            $this->rejects(fn () => $probe->selectOne(
                'SELECT id FROM operational_reconciliation_reviews WHERE id = ? FOR UPDATE NOWAIT', [$f['review']]
            ), 3572, 'NOWAIT');
            DB::commit();
            $probe->beginTransaction();
            $this->seal($probe, $f);
            $this->assertSame($f['chart'], (int) $probe->table('operational_reconciliation_charts')
                ->where('review_id', $f['review'])->value('chart_account_id'));
            $probe->rollBack();
        } finally {
            $this->cleanup($f);
        }
    }

    public static function chartMutations(): array
    {
        return [['insert'], ['update'], ['delete']];
    }

    private function chartMutation(string $operation, array $f): array
    {
        return match ($operation) {
            'insert' => ['INSERT INTO operational_reconciliation_charts
                (review_id,user_id,chart_account_id,type_snapshot,cash_role_snapshot,is_active_snapshot)
                VALUES (?,?,?,\'asset\',\'non_cash\',1)', [$f['review'], $f['owner'], $f['chart']]],
            'update' => ['UPDATE operational_reconciliation_charts SET is_active_snapshot = 0 WHERE review_id = ?',
                [$f['review']]],
            'delete' => ['DELETE FROM operational_reconciliation_charts WHERE review_id = ?', [$f['review']]],
        };
    }

    #[DataProvider('chartMutations')]
    public function test_child_source_wait_does_not_take_parent_first(string $operation): void
    {
        $f = $this->fixture();
        if ($operation !== 'insert') {
            DB::table('operational_reconciliation_charts')->insert($f['row']);
        }
        try {
            DB::beginTransaction();
            DB::table('chart_of_accounts')->where('id', $f['chart'])->lockForUpdate()->first();
            [$sql, $bindings] = $this->chartMutation($operation, $f);
            $job = $this->startJob($sql, $bindings);
            $this->assertJobWaiting($job);
            // NOWAIT here is standalone SQL, NOT a trigger guarantee. This
            // succeeds only if the waiting child has NOT already locked parent.
            DB::selectOne('SELECT id FROM operational_reconciliation_reviews WHERE id = ? FOR UPDATE NOWAIT', [$f['review']]);
            DB::commit();
            $this->assertTrue($this->readJobResult($job)['ok']);
        } finally {
            $this->stopJobs();
            $this->cleanup($f);
        }
    }

    #[DataProvider('chartMutations')]
    public function test_seal_first_source_order_blocks_child_then_rejects_sealed_state(string $operation): void
    {
        $f = $this->fixture();
        if ($operation !== 'insert') {
            DB::table('operational_reconciliation_charts')->insert($f['row']);
        }
        try {
            DB::beginTransaction();
            // Application protocol: owner/reviewer, source/live, review LAST.
            DB::table('users')->where('id', $f['owner'])->sharedLock()->first();
            DB::table('chart_of_accounts')->where('id', $f['chart'])->lockForUpdate()->first();
            $this->seal(DB::connection(), $f);
            [$sql, $bindings] = $this->chartMutation($operation, $f);
            $job = $this->startJob($sql, $bindings);
            $this->assertJobWaiting($job);
            DB::commit();
            $result = $this->readJobResult($job);
            $this->assertFalse($result['ok']);
            $this->assertSame(1644, $result['code']);
            $this->assertStringContainsString('Sealed reconciliation evidence', $result['message']);
            $this->assertSame($operation === 'insert' ? 0 : 1,
                DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])->count());
        } finally {
            $this->stopJobs();
            $this->cleanup($f);
        }
    }

    public function test_current_read_rejects_sealed_parent_despite_stale_repeatable_read_snapshot(): void
    {
        $f = $this->fixture();
        $probe = $this->probe();
        try {
            $probe->beginTransaction();
            $this->assertNull($probe->table('operational_reconciliation_reviews')->where('id', $f['review'])->value('completed_at'));
            DB::transaction(fn () => $this->seal(DB::connection(), $f));
            // This still sees the genuinely stale consistent-read snapshot.
            $this->assertNull($probe->table('operational_reconciliation_reviews')->where('id', $f['review'])->value('completed_at'));
            $this->rejects(fn () => $probe->table('operational_reconciliation_charts')->insert($f['row']), 1644, 'Sealed');
            $probe->rollBack();
            $this->assertDatabaseCount('operational_reconciliation_charts', 0);
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_supersession_waits_for_predecessor_then_uses_committed_state(): void
    {
        $f = $this->fixture();
        $this->seal(DB::connection(), $f);
        try {
            DB::beginTransaction();
            DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->lockForUpdate()->first();
            $job = $this->startJob('INSERT INTO operational_reconciliation_reviews
                (user_id,reference,historical_transaction_strategy,accounting_currency,transaction_high_water_id,
                scope_reviewed_at,supersedes_review_id) VALUES (?,?,\'historical_backfill\',\'SAR\',0,?,?)',
                [$f['owner'], (string) Str::uuid(), '2026-10-12 00:00:00', $f['review']]);
            $this->assertJobWaiting($job);
            DB::commit();
            $this->assertTrue($this->readJobResult($job)['ok']);
            $this->assertSame(2, DB::table('operational_reconciliation_reviews')->where('user_id', $f['owner'])->count());
        } finally {
            $this->stopJobs();
            $this->cleanup($f);
        }
    }

    public function test_header_implicit_owner_fk_can_wait_without_a_trigger_owner_lock(): void
    {
        $f = $this->fixture();
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $f['owner'])->lockForUpdate()->first();
            // No predecessor: the header trigger takes no explicit source lock.
            // Its restrictive owner FK still waits. Future application writes
            // must prelock owners before sources/reviews; triggers cannot promise
            // nonwaiting FK enforcement or fix arbitrary caller lock inversions.
            $job = $this->startJob('INSERT INTO operational_reconciliation_reviews
                (user_id,reference,historical_transaction_strategy,accounting_currency,transaction_high_water_id,
                scope_reviewed_at) VALUES (?,?,\'historical_backfill\',\'SAR\',0,?)',
                [$f['owner'], (string) Str::uuid(), '2026-10-12 00:00:00']);
            $this->assertJobWaiting($job);
            DB::commit();
            $this->assertTrue($this->readJobResult($job)['ok']);
            $this->assertSame(2, DB::table('operational_reconciliation_reviews')->where('user_id', $f['owner'])->count());
        } finally {
            $this->stopJobs();
            $this->cleanup($f);
        }
    }

    public static function liveSources(): array
    {
        return [['accounts'], ['categories'], ['journal_entries']];
    }

    #[DataProvider('liveSources')]
    public function test_other_child_sources_are_locked_before_parent(string $source): void
    {
        $f = $this->fixture();
        try {
            $id = DB::table($source)->insertGetId(match ($source) {
                'accounts' => ['user_id' => $f['owner'], 'name' => 'Source', 'type' => 'bank', 'balance' => '0.00', 'currency' => 'SAR'],
                'categories' => ['user_id' => $f['owner'], 'name' => 'Source', 'type' => 'income'],
                'journal_entries' => ['user_id' => $f['owner'], 'entry_date' => '2026-10-12', 'currency' => 'SAR'],
            });
            $this->fixtureSources[$f['owner']][$source][] = $id;
            // A represented chart is needed for account/category eligibility.
            DB::table('operational_reconciliation_charts')->insert($f['row']);
            if ($source === 'categories') {
                DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])->update([
                    'type_snapshot' => 'revenue', 'cash_role_snapshot' => 'non_cash',
                ]);
            }
            [$sql, $bindings] = match ($source) {
                'accounts' => ['INSERT INTO operational_reconciliation_accounts
                    (review_id,user_id,account_id,chart_account_id,stored_balance_snapshot,currency_snapshot)
                    VALUES (?,?,?,?,0,\'SAR\')', [$f['review'], $f['owner'], $id, $f['chart']]],
                'categories' => ['INSERT INTO operational_reconciliation_categories
                    (review_id,user_id,category_id,category_type_snapshot,chart_account_id,cash_flow_activity)
                    VALUES (?,?,?,\'income\',?,\'operating\')', [$f['review'], $f['owner'], $id, $f['chart']]],
                'journal_entries' => ['INSERT INTO operational_reconciliation_journals
                    (review_id,user_id,journal_entry_id) VALUES (?,?,?)', [$f['review'], $f['owner'], $id]],
            };
            DB::beginTransaction();
            DB::table($source)->where('id', $id)->lockForUpdate()->first();
            $job = $this->startJob($sql, $bindings);
            $this->assertJobWaiting($job);
            DB::selectOne('SELECT id FROM operational_reconciliation_reviews WHERE id = ? FOR UPDATE NOWAIT', [$f['review']]);
            DB::commit();
            $result = $this->readJobResult($job);
            if ($source === 'journal_entries') {
                // Current source becomes readable, but draft eligibility still blocks.
                $this->assertFalse($result['ok']);
                $this->assertSame(1644, $result['code']);
                $this->assertStringContainsString('posted journal', $result['message']);
            } else {
                $this->assertTrue($result['ok']);
            }
        } finally {
            $this->stopJobs();
            $this->cleanup($f);
        }
    }
}
