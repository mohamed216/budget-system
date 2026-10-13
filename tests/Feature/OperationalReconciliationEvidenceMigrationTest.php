<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithEvidenceProtectionTriggers;
use Tests\TestCase;

class OperationalReconciliationEvidenceMigrationTest extends TestCase
{
    use InteractsWithEvidenceProtectionTriggers;

    private function migrations(): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'DDL tests require the isolated database without transactional fixtures.');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $binding = require database_path('migrations/2026_10_10_000016_bind_operational_reconciliation_reviews.php');
        $binding->down();
        // Exercise foundation rollback without its later protection dependency.
        $protection = require database_path('migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php');
        $this->assertEvidenceEmpty();
        $this->assertProtectionTriggersInstalledExactly();
        $protection->down();
        $this->beforeApplicationDestroyed(function () use ($protection, $binding): void {
            $this->assertEvidenceEmpty();
            $protection->up();
            $this->assertProtectionTriggersInstalledExactly();
            $binding->up();
        });
        $migrations = [];
        foreach (range(9, 14) as $number) {
            $paths = glob(database_path(sprintf('migrations/2026_10_10_%06d_*.php', $number)));
            $this->assertCount(1, $paths);
            $migrations[$number] = require $paths[0];
        }

        return $migrations;
    }

    private function assertEvidenceEmpty(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function tables(): array
    {
        return [
            9 => 'operational_reconciliation_reviews', 10 => 'operational_reconciliation_charts',
            11 => 'operational_reconciliation_accounts', 12 => 'operational_reconciliation_categories',
            13 => 'operational_reconciliation_journals', 14 => 'operational_reconciliation_attributions',
        ];
    }

    private function refuses(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Unsafe evidence migration operation was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function restore(array $migrations): void
    {
        foreach ($this->tables() as $number => $table) {
            if (! Schema::hasTable($table)) {
                $migrations[$number]->up();
            }
        }
    }

    public function test_empty_reverse_rollback_and_reupgrade_create_no_evidence_or_change_existing_rows(): void
    {
        $migrations = $this->migrations();
        $before = [];
        foreach (['accounts', 'categories', 'transactions', 'journal_entries', 'journal_lines',
            'operational_accounting_integrations', 'operational_accounting_account_scope',
            'operational_accounting_category_scope', 'operational_account_mappings', 'operational_category_mappings'] as $table) {
            $before[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        try {
            foreach (array_reverse($migrations, true) as $number => $migration) {
                $this->assertDatabaseCount($this->tables()[$number], 0);
                $migration->down();
                $this->assertFalse(Schema::hasTable($this->tables()[$number]));
            }
            foreach ($migrations as $number => $migration) {
                $migration->up();
                $this->assertDatabaseCount($this->tables()[$number], 0);
                $this->refuses(fn () => $migration->up(), 'already exists');
            }
            foreach ($before as $table => $rows) {
                $this->assertEquals($rows, DB::table($table)->get()->map(fn ($row) => (array) $row)->all(), $table);
            }
        } finally {
            $this->restore($migrations);
        }
    }

    public function test_empty_parent_rollback_refuses_dependencies_and_unknown_external_foreign_keys(): void
    {
        $migrations = $this->migrations();
        $this->refuses(fn () => $migrations[9]->down(), 'Drop dependent evidence table');
        $probe = 'reconciliation_evidence_rollback_probe';
        $this->assertFalse(Schema::hasTable($probe));
        try {
            DB::statement(<<<'SQL'
CREATE TABLE `reconciliation_evidence_rollback_probe` (
    `attribution_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`attribution_id`),
    CONSTRAINT `reconciliation_probe_fk` FOREIGN KEY (`attribution_id`)
        REFERENCES `operational_reconciliation_attributions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB
SQL);
            $this->refuses(fn () => $migrations[14]->down(), 'External constraints still reference');
            $this->assertTrue(Schema::hasTable('operational_reconciliation_attributions'));
        } finally {
            if (Schema::hasTable($probe)) {
                Schema::drop($probe);
            }
        }
    }

    public function test_parent_preflight_rejects_missing_keys_and_incompatible_types(): void
    {
        $migrations = $this->migrations();
        $probe = 'reconciliation_evidence_parent_probe';
        $this->assertFalse(Schema::hasTable($probe));
        $guard = new \ReflectionMethod($migrations[9], 'requireParent');
        $this->refuses(fn () => $guard->invoke($migrations[9], $probe, ['id']), 'Missing InnoDB referenceable parent key');
        try {
            DB::statement('CREATE TABLE `reconciliation_evidence_parent_probe` (`id` INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $this->refuses(fn () => $guard->invoke($migrations[9], $probe, ['id']), 'Incompatible parent column');
            DB::statement('ALTER TABLE `reconciliation_evidence_parent_probe` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL, DROP PRIMARY KEY');
            $this->refuses(fn () => $guard->invoke($migrations[9], $probe, ['id']), 'Missing InnoDB referenceable parent key');
        } finally {
            if (Schema::hasTable($probe)) {
                Schema::drop($probe);
            }
        }
    }

    public function test_upgrade_refuses_existing_completion_evidence_without_inventing_reviews(): void
    {
        $migrations = $this->migrations();
        $owner = User::factory()->create();
        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            DB::table('operational_accounting_integrations')->insert([
                'user_id' => $owner->id, 'state' => 'reconciling',
                'reconciliation_reference' => 'Existing external evidence',
                'reconciled_at' => '2026-10-12 00:00:00.123456',
            ]);
            $before = (array) DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->first();
            $this->refuses(fn () => $migrations[9]->up(), 'Existing reconciliation evidence requires explicit review');
            $this->assertFalse(Schema::hasTable('operational_reconciliation_reviews'));
            $this->assertSame($before, (array) DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->first());
        } finally {
            DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->delete();
            $this->restore($migrations);
            $owner->delete();
        }
    }

    public function test_upgrade_rejects_incompatible_preexisting_table_without_adopting_it(): void
    {
        $migrations = $this->migrations();
        $stubCreated = false;
        try {
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            DB::statement('CREATE TABLE `operational_reconciliation_reviews` (`id` INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $stubCreated = true;
            $this->refuses(fn () => $migrations[9]->up(), 'already exists');
            $this->assertSame(['id'], Schema::getColumnListing('operational_reconciliation_reviews'));
            $this->assertSame('int unsigned', strtolower(Schema::getColumns('operational_reconciliation_reviews')[0]['type']));
        } finally {
            if ($stubCreated) {
                Schema::drop('operational_reconciliation_reviews');
            }
            $this->restore($migrations);
        }
    }
}
