<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/** Privileged, isolated-test DDL only; never an application authorization path. */
trait InteractsWithActiveProtectionTriggers
{
    private function activeProtectionMigration(): object
    {
        return require database_path('migrations/2026_10_14_000001_protect_active_operational_accounting.php');
    }

    private function activeProtectionDefinitions(): array
    {
        $migration = $this->activeProtectionMigration();

        return (new \ReflectionMethod($migration, 'definitions'))->invoke($migration);
    }

    private function previousActiveProtectionBody(string $body): string
    {
        // Exact inverse of the immediately previous reviewed D1 iteration:
        // every dependency read had NOWAIT and every outer body had this handler.
        // Compare the entire derived body below, never SQL fragments or patterns.
        $prefix = "BEGIN\nDECLARE gate_state VARCHAR(16) DEFAULT NULL;\nDECLARE gate_reference VARCHAR(255) DEFAULT NULL;\nDECLARE selected_id BIGINT UNSIGNED DEFAULT NULL;\n";
        if (! str_starts_with($body, $prefix)) {
            throw new \RuntimeException('Unexpected D1 body structure; review the test-only previous-definition allowlist.');
        }
        $handler = "DECLARE EXIT HANDLER FOR 3572\n    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ACTIVE dependency is busy; retry the mutation';\n";

        return $prefix.$handler.str_replace(' FOR SHARE;', ' FOR SHARE NOWAIT;', substr($body, strlen($prefix)));
    }

    private function restoreActiveProtectionTriggers(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $creator = DB::selectOne('SELECT CURRENT_USER() AS identity')->identity;
        $restore = [];
        foreach ($this->activeProtectionDefinitions() as $name => [$table, $event, $body]) {
            $actual = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->first();
            if ($actual !== null) {
                $identityMatches = $actual->EVENT_OBJECT_TABLE === $table
                    && $actual->EVENT_MANIPULATION === $event
                    && $actual->ACTION_TIMING === 'BEFORE'
                    && $actual->DEFINER === $creator;
                $actualBody = trim($actual->ACTION_STATEMENT);
                $current = $actualBody === trim($body);
                $previous = $actualBody === trim($this->previousActiveProtectionBody($body));
                $knownConflict = $name === 'phase3_active_account_bu'
                    && $actualBody === "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test conflict'";
                if (! $identityMatches || ! ($current || $previous || $knownConflict)) {
                    throw new \RuntimeException("Unknown test trigger {$name} on {$actual->EVENT_OBJECT_TABLE}, defined by {$actual->DEFINER}; refusing fixture restoration.");
                }
                if ($current) {
                    continue;
                }
            }
            $restore[$name] = [$table, $event, $body, $actual !== null];
        }
        // Validate all known identities/bodies before dropping any test guard.
        foreach ($restore as $name => [$table, $event, $body, $exists]) {
            if ($exists) {
                DB::unprepared("DROP TRIGGER `{$name}`");
            }
            DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
        }
        $this->assertActiveProtectionTriggersInstalledExactly();
    }

    private function assertActiveProtectionTriggersInstalledExactly(): void
    {
        $definitions = $this->activeProtectionDefinitions();
        $creator = DB::selectOne('SELECT CURRENT_USER() AS identity')->identity;
        $actual = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where(fn ($query) => $query->whereIn('TRIGGER_NAME', array_keys($definitions))
                ->orWhereRaw('LEFT(TRIGGER_NAME, 14) = ?', ['phase3_active_']))->get()->keyBy('TRIGGER_NAME');
        $this->assertCount(21, $actual);
        foreach ($definitions as $name => [$table, $event, $body]) {
            $this->assertTrue($actual->has($name), "Missing D1 test guard {$name}");
            $trigger = $actual->get($name);
            $this->assertSame([$table, 'BEFORE', $event, trim($body), $creator],
                [$trigger->EVENT_OBJECT_TABLE, $trigger->ACTION_TIMING, $trigger->EVENT_MANIPULATION, trim($trigger->ACTION_STATEMENT), $trigger->DEFINER], $name);
        }
    }

    private function withoutActiveIntegrationDeleteGuard(callable $cleanup): void
    {
        $this->assertSame(0, DB::transactionLevel());
        [$table, $event, $body] = $this->activeProtectionDefinitions()['phase3_active_oai_bd'];
        $actual = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'phase3_active_oai_bd')->first();
        $this->assertNotNull($actual);
        $this->assertSame(trim($body), trim($actual->ACTION_STATEMENT));
        DB::unprepared('DROP TRIGGER phase3_active_oai_bd');
        try {
            $cleanup();
        } finally {
            DB::unprepared("CREATE TRIGGER phase3_active_oai_bd BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
        }
    }
}
