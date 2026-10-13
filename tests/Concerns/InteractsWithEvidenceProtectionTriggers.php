<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Throwable;

trait InteractsWithEvidenceProtectionTriggers
{
    private function evidenceProtectionDefinitions(): array
    {
        $migration = require database_path('migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php');

        return (new \ReflectionMethod($migration, 'definitions'))->invoke($migration);
    }

    private function assertProtectionTriggersInstalledExactly(): void
    {
        $definitions = $this->evidenceProtectionDefinitions();
        $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where(fn ($query) => $query->whereIn('TRIGGER_NAME', array_keys($definitions))
                ->orWhereIn('EVENT_OBJECT_TABLE', array_column($definitions, 0)))
            ->get()->keyBy('TRIGGER_NAME');
        $this->assertCount(count($definitions), $triggers);
        foreach ($definitions as $name => [$table, $event, $body]) {
            $this->assertTrue($triggers->has($name), "Missing evidence trigger {$name}");
            $actual = $triggers[$name];
            $this->assertSame($table, $actual->EVENT_OBJECT_TABLE, $name);
            $this->assertSame('BEFORE', $actual->ACTION_TIMING, $name);
            $this->assertSame($event, $actual->EVENT_MANIPULATION, $name);
            $this->assertSame(trim($body), trim($actual->ACTION_STATEMENT), $name);
        }
    }

    private function restoreProtectionTriggers(): void
    {
        // Test-only restoration of the set verified before destructive tests.
        // This is deliberately NOT migration up(): no adoption/rollback guard
        // changes. Never drop unknown names or edit production definitions.
        $failure = null;
        foreach ($this->evidenceProtectionDefinitions() as $name => [$table, $event, $body]) {
            try {
                $actual = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                    ->where('TRIGGER_NAME', $name)->first();
                if ($actual !== null && $actual->EVENT_OBJECT_TABLE === $table && $actual->ACTION_TIMING === 'BEFORE'
                    && $actual->EVENT_MANIPULATION === $event && trim($actual->ACTION_STATEMENT) === trim($body)) {
                    continue;
                }
                if ($actual !== null) {
                    DB::unprepared("DROP TRIGGER `{$name}`");
                }
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
            } catch (Throwable $exception) {
                // Try EVERY missing guard even if one restoration fails.
                $failure ??= $exception;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
