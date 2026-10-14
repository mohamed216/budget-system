<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithActiveProtectionTriggers;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OperationalAccountingActiveProtectionMigrationTest extends TestCase
{
    use InteractsWithActiveProtectionTriggers;
    use InteractsWithReconciliationFixtures {
        setUp as private setUpFixtures;
        tearDown as private tearDownFixtures;
    }

    protected function setUp(): void
    {
        $this->setUpFixtures();
        // Only this test creates the wrong-table conflict fixture. Recover its
        // exact leftover here, without broadening the generic restore helper.
        $leftover = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'phase3_active_account_bu')->first();
        if ($leftover !== null && $leftover->EVENT_OBJECT_TABLE === 'users') {
            $this->removeSyntheticConflictTrigger('users');
        }
        $this->restoreActiveProtectionTriggers();
        $this->assertActiveProtectionTriggersInstalledExactly();
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreActiveProtectionTriggers();
        } finally {
            $this->tearDownFixtures();
        }
    }

    private function triggers(): array
    {
        return DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', array_keys($this->activeProtectionDefinitions()))->orderBy('TRIGGER_NAME')->get()->all();
    }

    private function refuses(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Unsafe ACTIVE migration accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_fresh_guard_installation_exact_definitions_and_creator_definer(): void
    {
        $migration = $this->activeProtectionMigration();
        $migration->down();
        $this->assertSame([], $this->triggers());
        $migration->up();
        $definitions = $this->activeProtectionDefinitions();
        $this->assertCount(21, $this->triggers());
        $creator = DB::selectOne('SELECT CURRENT_USER() AS identity')->identity;
        foreach ($this->triggers() as $trigger) {
            [$table, $event, $body] = $definitions[$trigger->TRIGGER_NAME];
            $this->assertSame([$table, 'BEFORE', $event, trim($body), $creator],
                [$trigger->EVENT_OBJECT_TABLE, $trigger->ACTION_TIMING, $trigger->EVENT_MANIPULATION, trim($trigger->ACTION_STATEMENT), $trigger->DEFINER]);
            $this->assertStringNotContainsString('FOR UPDATE', $body);
            $this->assertStringNotContainsString('@', $body);
            $this->assertStringNotContainsString('JOIN ', $body);
            preg_match_all('/SELECT\b[\s\S]*?;/', $body, $reads);
            $this->assertSame(preg_match_all('/\bSELECT\b/', $body), count($reads[0]));
            foreach ($reads[0] as $read) {
                // The locking clause must terminate each dependency SELECT,
                // not merely occur somewhere in its text.
                $this->assertMatchesRegularExpression('/\ASELECT\b[\s\S]*\bFOR SHARE\s*;\z/', $read);
            }
        }
        $this->assertProtectionTriggersInstalledExactly();
    }

    public function test_upgrade_and_safe_rollback_preserve_existing_ready_data(): void
    {
        $f = $this->boundFixture();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'ready']);
        $before = $this->allFixtureRows($f['owner']->id);
        $migration = $this->activeProtectionMigration();
        $migration->down();
        $migration->down();
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $migration->up();
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->refuses(fn () => $migration->up(), 'already exist');
    }

    private function removeSyntheticConflictTrigger(string $table): void
    {
        $name = 'phase3_active_account_bu';
        $creator = DB::selectOne('SELECT CURRENT_USER() AS identity')->identity;
        $actual = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', $name)->first();
        $expected = [$name, $table, 'BEFORE', 'UPDATE', "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test conflict'", $creator];
        $identity = $actual === null ? null : [$actual->TRIGGER_NAME, $actual->EVENT_OBJECT_TABLE, $actual->ACTION_TIMING,
            $actual->EVENT_MANIPULATION, trim($actual->ACTION_STATEMENT), $actual->DEFINER];
        if (! in_array($table, ['users', 'accounts'], true) || $identity !== $expected) {
            throw new \RuntimeException('Unexpected synthetic conflict trigger; refusing test cleanup.');
        }
        DB::unprepared("DROP TRIGGER `{$name}`");
    }

    public function test_conflicting_trigger_identity_or_body_refuses_before_any_ddl(): void
    {
        $name = 'phase3_active_account_bu';
        foreach (['users', 'accounts'] as $table) {
            $installed = false;
            try {
                DB::unprepared("DROP TRIGGER {$name}");
                DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test conflict'");
                $installed = true;
                $before = $this->triggers();
                $this->refuses(fn () => $this->activeProtectionMigration()->up(), 'Unexpected ACTIVE protection trigger');
                $this->refuses(fn () => $this->activeProtectionMigration()->down(), 'Unexpected ACTIVE protection trigger');
                $this->assertEquals($before, $this->triggers());
            } finally {
                if ($installed) {
                    $this->removeSyntheticConflictTrigger($table);
                }
                $this->restoreActiveProtectionTriggers();
                $this->assertActiveProtectionTriggersInstalledExactly();
            }
        }
    }

    public function test_partial_installation_is_not_adopted_but_can_be_safely_removed_without_active_data(): void
    {
        try {
            DB::unprepared('DROP TRIGGER phase3_active_je_publish_bu');
            $before = $this->triggers();
            $this->refuses(fn () => $this->activeProtectionMigration()->up(), 'installation is partial');
            $this->assertEquals($before, $this->triggers());
            $this->activeProtectionMigration()->down();
            $this->assertSame([], $this->triggers());
            $this->activeProtectionMigration()->up();
        } finally {
            $this->restoreActiveProtectionTriggers();
            $this->assertActiveProtectionTriggersInstalledExactly();
        }
    }

    public function test_unexpected_active_data_is_not_repaired_or_adopted(): void
    {
        $this->activeProtectionMigration()->down();
        $f = $this->boundFixture();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => '2026-10-14 12:00:00.123456']);
        $before = $this->allFixtureRows($f['owner']->id);
        $this->refuses(fn () => $this->activeProtectionMigration()->up(), 'ACTIVE owners exist');
        $this->assertSame([], $this->triggers());
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_down_refuses_removing_guards_when_active_owner_exists(): void
    {
        $f = $this->boundFixture();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => '2026-10-14 12:00:00.123456']);
        $before = $this->triggers();
        $this->refuses(fn () => $this->activeProtectionMigration()->down(), 'ACTIVE owners exist');
        $this->assertEquals($before, $this->triggers());
    }

    public function test_unknown_trigger_and_incompatible_foundation_are_not_ignored(): void
    {
        DB::unprepared("CREATE TRIGGER phase3_active_unknown_probe BEFORE UPDATE ON accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unknown test guard'");
        try {
            $this->refuses(fn () => $this->activeProtectionMigration()->down(), 'Unexpected ACTIVE protection trigger');
        } finally {
            DB::unprepared('DROP TRIGGER phase3_active_unknown_probe');
        }
        $foundation = require database_path('migrations/2026_10_10_000006_protect_operational_accounting_links.php');
        [$table, $event, $body] = (new \ReflectionMethod($foundation, 'definitions'))->invoke($foundation)['phase3_otj_validate_bi'];
        DB::unprepared('DROP TRIGGER phase3_otj_validate_bi');
        DB::unprepared("CREATE TRIGGER phase3_otj_validate_bi BEFORE INSERT ON operational_transaction_journals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Altered test guard'");
        try {
            $before = $this->triggers();
            $this->refuses(fn () => $this->activeProtectionMigration()->up(), 'incompatible foundation trigger');
            $this->assertEquals($before, $this->triggers());
        } finally {
            DB::unprepared('DROP TRIGGER phase3_otj_validate_bi');
            DB::unprepared("CREATE TRIGGER phase3_otj_validate_bi BEFORE {$event} ON {$table} FOR EACH ROW {$body}");
        }
    }
}
