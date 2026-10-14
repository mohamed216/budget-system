<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCompletedReconciliationBindings;
use Tests\Concerns\InteractsWithActiveProtectionTriggers;
use Tests\Concerns\InteractsWithEvidenceProtectionTriggers;
use Tests\TestCase;
use Throwable;

class OperationalReconciliationBindingMigrationTest extends TestCase
{
    use CreatesCompletedReconciliationBindings;
    use InteractsWithActiveProtectionTriggers;
    use InteractsWithEvidenceProtectionTriggers;

    private array $ownerIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, DB::transactionLevel());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        // Later guards must be removed before exercising the earlier binding rollback.
        $this->activeProtectionMigration()->down();
        $this->assertProtectionTriggersInstalledExactly();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_10_000016_bind_operational_reconciliation_reviews.php');
    }

    private function fixture(): array
    {
        $owner = User::factory()->create()->id;
        $this->ownerIds[] = $owner;

        return $this->completedBindingFixture(['user_id' => $owner, 'state' => 'reconciling',
            'historical_transaction_strategy' => 'balance_only', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 42, 'scope_reviewed_at' => '2026-10-10 09:00:00',
            'reconciled_at' => '2026-10-10 10:00:00.123456']);
    }

    protected function tearDown(): void
    {
        $failure = null;
        try {
            DB::table('operational_accounting_integrations')->whereIn('user_id', $this->ownerIds)->delete();
            $this->restoreProtectionTriggers();
            $this->cleanupBindingFixtures($this->ownerIds);
            DB::table('users')->whereIn('id', $this->ownerIds)->delete();
            $migration = $this->migration();
            $objects = (new \ReflectionMethod($migration, 'inspectObjects'))->invoke($migration);
            if ($objects === []) {
                $migration->up();
            }
            $this->assertProtectionTriggersInstalledExactly();
            $this->restoreActiveProtectionTriggers();
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            parent::tearDown();
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function refuses(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Unsafe reconciliation binding migration accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function bindingTriggers(): array
    {
        return DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', ['oai_review_bi', 'oai_review_bu'])->orderBy('TRIGGER_NAME')->pluck('TRIGGER_NAME')->all();
    }

    public function test_exact_schema_nullable_binding_precision_and_restrictive_owner_key(): void
    {
        $foreign = collect(Schema::getForeignKeys('operational_accounting_integrations'))->firstWhere('name', 'oai_review_owner_fk');
        $this->assertNotNull($foreign);
        $this->assertSame(['user_id', 'reconciliation_reference'], $foreign['columns']);
        $this->assertSame('operational_reconciliation_reviews', $foreign['foreign_table']);
        $this->assertSame(['user_id', 'reference'], $foreign['foreign_columns']);
        $this->assertSame('restrict', strtolower($foreign['on_delete']));
        $this->assertSame('restrict', strtolower($foreign['on_update']));
        $index = collect(Schema::getIndexes('operational_accounting_integrations'))->firstWhere('name', 'oai_review_ref_idx');
        $this->assertSame(['user_id', 'reconciliation_reference'], $index['columns']);
        $columns = collect(Schema::getColumns('operational_accounting_integrations'))->keyBy('name');
        $this->assertSame('varchar(255)', $columns['reconciliation_reference']['type']);
        $this->assertTrue($columns['reconciliation_reference']['nullable']);
        $this->assertSame('datetime(6)', $columns['reconciled_at']['type']);
        $this->assertSame('timestamp', $columns['scope_reviewed_at']['type']);
        foreach ([['operational_accounting_integrations', 'reconciliation_reference'], ['operational_reconciliation_reviews', 'reference']] as [$table, $column]) {
            $metadata = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->first();
            $this->assertSame('utf8mb4', $metadata->CHARACTER_SET_NAME);
            $this->assertSame('utf8mb4_unicode_ci', $metadata->COLLATION_NAME);
        }
        $clause = DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', 'oai_review_pair_check')->value('CHECK_CLAUSE');
        $migration = $this->migration();
        $this->assertTrue((new \ReflectionMethod($migration, 'matchesPairCheck'))->invoke($migration, $clause));
        $this->assertSame(['oai_review_bi', 'oai_review_bu'], $this->bindingTriggers());
        $row = $this->fixture();
        DB::table('operational_accounting_integrations')->insert(['user_id' => $row['user_id']]);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $row['user_id'],
            'state' => 'legacy', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_rollback_reupgrade_preserves_bound_rows_and_all_evidence(): void
    {
        $row = $this->fixture();
        DB::table('operational_accounting_integrations')->insert($row);
        $before = DB::table('operational_reconciliation_reviews')->where('user_id', $row['user_id'])->get()->all();
        $this->migration()->down();
        $this->assertSame([], $this->bindingTriggers());
        $this->assertProtectionTriggersInstalledExactly();
        $this->assertDatabaseHas('operational_accounting_integrations', $row);
        $this->migration()->up();
        $this->assertEquals($before, DB::table('operational_reconciliation_reviews')->where('user_id', $row['user_id'])->get()->all());
        $this->assertDatabaseHas('operational_accounting_integrations', $row);
        $this->refuses(fn () => $this->migration()->up(), 'already exist');
    }

    public static function invalidExistingRows(): array
    {
        return [
            'half reference' => [['reconciliation_reference' => null]],
            'half time' => [['reconciled_at' => null]],
            'missing review' => [['reconciliation_reference' => 'Existing external evidence']],
            'strategy' => [['historical_transaction_strategy' => 'historical_backfill']],
            'currency' => [['accounting_currency' => 'USD']],
            'boundary' => [['transaction_high_water_id' => 43]],
            'scope' => [['scope_reviewed_at' => '2026-10-10 09:00:01']],
            'microseconds' => [['reconciled_at' => '2026-10-10 10:00:00.123457']],
            'legacy binding' => [['state' => 'legacy']],
        ];
    }

    #[DataProvider('invalidExistingRows')]
    public function test_invalid_existing_rows_refuse_before_any_binding_object_is_created(array $changes): void
    {
        $row = $this->fixture();
        $this->migration()->down();
        $row = array_replace($row, $changes);
        DB::table('operational_accounting_integrations')->insert($row);
        $this->refuses(fn () => $this->migration()->up(), 'Invalid existing reconciliation binding');
        $this->assertSame([], $this->bindingTriggers());
        $this->assertFalse(collect(Schema::getForeignKeys('operational_accounting_integrations'))->contains('name', 'oai_review_owner_fk'));
        $this->assertFalse(DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', 'oai_review_pair_check')->exists());
        $this->assertDatabaseHas('operational_accounting_integrations', $row);
        $this->assertProtectionTriggersInstalledExactly();
    }

    public function test_foreign_and_case_variant_existing_bindings_are_not_adopted(): void
    {
        Str::createUuidsUsing(fn () => \Ramsey\Uuid\Uuid::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        try {
            $row = $this->fixture();
        } finally {
            Str::createUuidsNormally();
        }
        $foreign = $this->fixture();
        $this->migration()->down();
        foreach ([$foreign['reconciliation_reference'], strtoupper($row['reconciliation_reference'])] as $reference) {
            // The deterministic canonical UUID guarantees a distinct case variant.
            $this->assertNotSame($row['reconciliation_reference'], $reference);
            DB::table('operational_accounting_integrations')->insert(array_replace($row, ['reconciliation_reference' => $reference]));
            $this->refuses(fn () => $this->migration()->up(), 'Invalid existing');
            $this->assertSame([], $this->bindingTriggers());
            DB::table('operational_accounting_integrations')->where('user_id', $row['user_id'])->delete();
        }
    }

    public function test_unsealed_existing_review_binding_is_not_adopted(): void
    {
        $row = $this->fixture();
        $reference = (string) Str::uuid();
        $id = DB::table('operational_reconciliation_reviews')->insertGetId([
            'user_id' => $row['user_id'], 'reference' => $reference,
            'historical_transaction_strategy' => 'historical_backfill', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 42, 'scope_reviewed_at' => $row['scope_reviewed_at'],
        ]);
        $this->bindingFixtureReviewIds[$id] = $row['user_id'];
        $this->migration()->down();
        DB::table('operational_accounting_integrations')->insert(array_replace($row, [
            'historical_transaction_strategy' => 'historical_backfill', 'reconciliation_reference' => $reference,
        ]));
        $this->refuses(fn () => $this->migration()->up(), 'Invalid existing');
        $this->assertSame([], $this->bindingTriggers());
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $id, 'reviewed_by' => null, 'completed_at' => null]);
    }

    public function test_restrictive_review_fk_blocks_delete_and_reference_update_independently_of_seal_guards(): void
    {
        $row = $this->fixture();
        DB::table('operational_accounting_integrations')->insert($row);
        try {
            // Disposable test fixture only; verify the FK itself, without altering
            // 000015 source or relying on its earlier immutability rejection.
            DB::unprepared('DROP TRIGGER orr_header_bd');
            DB::unprepared('DROP TRIGGER orr_header_bu');
            foreach (['delete', 'update'] as $operation) {
                try {
                    $query = DB::table('operational_reconciliation_reviews')->where('user_id', $row['user_id']);
                    $operation === 'delete' ? $query->delete() : $query->update(['reference' => (string) Str::uuid()]);
                    $this->fail('Bound review FK allowed a destructive change.');
                } catch (QueryException $exception) {
                    $this->assertSame(1451, $exception->errorInfo[1]);
                }
            }
        } finally {
            $this->restoreProtectionTriggers();
        }
        $this->assertDatabaseHas('operational_accounting_integrations', $row);
    }

    public function test_unknown_or_altered_binding_trigger_refuses_rollback_before_any_drop(): void
    {
        $migration = $this->migration();
        $definitions = (new \ReflectionMethod($migration, 'definitions'))->invoke($migration);
        try {
            DB::unprepared('DROP TRIGGER oai_review_bu');
            DB::unprepared("CREATE TRIGGER oai_review_bu BEFORE UPDATE ON operational_accounting_integrations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Altered binding guard'");
            $this->refuses(fn () => $migration->down(), 'Unexpected reconciliation binding trigger');
            $this->assertSame(['oai_review_bi', 'oai_review_bu'], $this->bindingTriggers());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS oai_review_bu');
            DB::unprepared('CREATE TRIGGER oai_review_bu BEFORE UPDATE ON operational_accounting_integrations FOR EACH ROW '.$definitions['oai_review_bu'][1]);
        }
        DB::unprepared('CREATE TRIGGER oai_unknown_probe BEFORE DELETE ON operational_accounting_integrations FOR EACH ROW SET @oai_unknown_probe = 1');
        try {
            $this->refuses(fn () => $migration->down(), 'Unexpected reconciliation binding trigger');
            $this->assertSame(['oai_review_bi', 'oai_review_bu'], $this->bindingTriggers());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS oai_unknown_probe');
        }
    }
}
