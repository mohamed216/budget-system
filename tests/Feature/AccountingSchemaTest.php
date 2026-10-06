<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingSchemaTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(int $user, array $values = []): int
    {
        return DB::table('chart_of_accounts')->insertGetId(array_replace([
            'user_id' => $user, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset',
        ], $values));
    }

    private function journal(int $user, array $values = []): int
    {
        // Insert draft first: schema status checks are exercised through the permitted transition.
        $id = DB::table('journal_entries')->insertGetId([
            'user_id' => $user, 'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
        ]);
        if ($values !== []) {
            DB::table('journal_entries')->where('id', $id)->update($values);
        }

        return $id;
    }

    private function line(int $user, int $journal, int $account, array $values = []): int
    {
        return DB::table('journal_lines')->insertGetId(array_replace([
            'user_id' => $user, 'journal_entry_id' => $journal, 'chart_account_id' => $account,
            'line_number' => 1, 'debit' => '1.00', 'credit' => '0.00',
        ], $values));
    }

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject the invalid schema operation.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    public function test_tables_columns_defaults_and_storage_engine_match_contract(): void
    {
        $expected = [
            'chart_of_accounts' => [
                'id' => ['bigint unsigned', false, null], 'user_id' => ['bigint unsigned', false, null],
                'parent_id' => ['bigint unsigned', true, null], 'code' => ['varchar(32)', false, null],
                'name' => ['varchar(255)', false, null], 'type' => ['varchar(16)', false, null],
                'is_active' => ['tinyint(1)', false, '1'],
            ],
            'journal_entries' => [
                'id' => ['bigint unsigned', false, null], 'user_id' => ['bigint unsigned', false, null],
                'entry_date' => ['date', false, null], 'currency' => ['char(3)', false, null],
                'reference' => ['varchar(100)', true, null], 'description' => ['text', true, null],
                'status' => ['varchar(16)', false, 'draft'], 'version' => ['int unsigned', false, '1'],
                'posted_at' => ['datetime(6)', true, null],
            ],
            'journal_lines' => [
                'id' => ['bigint unsigned', false, null], 'user_id' => ['bigint unsigned', false, null],
                'journal_entry_id' => ['bigint unsigned', false, null], 'chart_account_id' => ['bigint unsigned', false, null],
                'line_number' => ['smallint unsigned', false, null], 'debit' => ['decimal(15,2)', false, '0.00'],
                'credit' => ['decimal(15,2)', false, '0.00'], 'description' => ['varchar(1000)', true, null],
            ],
        ];
        foreach ($expected as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table));
            $actual = collect(Schema::getColumns($table))->keyBy('name');
            foreach (['created_at', 'updated_at'] as $timestamp) {
                $columns[$timestamp] = ['timestamp', true, null];
            }
            $this->assertSame(array_keys($columns), $actual->keys()->all());
            foreach ($columns as $name => [$type, $nullable, $default]) {
                $this->assertSame([$type, $nullable, $default], [$actual[$name]['type'], $actual[$name]['nullable'], $actual[$name]['default']], "{$table}.{$name}");
            }
            $this->assertTrue($actual['id']['auto_increment']);
            $engine = DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::connection()->getDatabaseName(), $table]);
            $this->assertSame('InnoDB', $engine->ENGINE);
        }
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'balance'));
        $this->assertFalse(Schema::hasColumn('journal_entries', 'total_debit'));
        $this->assertFalse(Schema::hasColumn('journal_entries', 'total_credit'));
    }

    public function test_indexes_and_composite_foreign_keys_match_contract(): void
    {
        $indexes = [
            'chart_of_accounts' => ['coa_owner_code_unique' => [['user_id', 'code'], true], 'coa_id_owner_unique' => [['id', 'user_id'], true], 'coa_parent_owner_index' => [['parent_id', 'user_id'], false], 'coa_owner_type_active_index' => [['user_id', 'type', 'is_active', 'id'], false]],
            'journal_entries' => ['je_id_owner_unique' => [['id', 'user_id'], true], 'je_owner_status_date_index' => [['user_id', 'status', 'entry_date', 'id'], false]],
            'journal_lines' => ['jl_entry_number_unique' => [['journal_entry_id', 'line_number'], true], 'jl_entry_owner_index' => [['journal_entry_id', 'user_id'], false], 'jl_account_owner_index' => [['chart_account_id', 'user_id'], false]],
        ];
        $foreignKeys = [
            'chart_of_accounts' => ['coa_user_fk' => [['user_id'], 'users', ['id']], 'coa_parent_owner_fk' => [['parent_id', 'user_id'], 'chart_of_accounts', ['id', 'user_id']]],
            'journal_entries' => ['je_user_fk' => [['user_id'], 'users', ['id']]],
            'journal_lines' => ['jl_entry_owner_fk' => [['journal_entry_id', 'user_id'], 'journal_entries', ['id', 'user_id']], 'jl_account_owner_fk' => [['chart_account_id', 'user_id'], 'chart_of_accounts', ['id', 'user_id']]],
        ];
        foreach ($indexes as $table => $definitions) {
            $actual = collect(Schema::getIndexes($table))->keyBy('name');
            $this->assertTrue($actual['primary']['primary']);
            foreach ($definitions as $name => [$columns, $unique]) {
                $this->assertSame([$columns, $unique], [$actual[$name]['columns'], $actual[$name]['unique']]);
            }
            $keys = collect(Schema::getForeignKeys($table))->keyBy('name');
            $this->assertCount(count($foreignKeys[$table]), $keys);
            foreach ($foreignKeys[$table] as $name => [$columns, $parent, $parentColumns]) {
                $key = $keys[$name];
                $this->assertSame([$columns, $parent, $parentColumns], [$key['columns'], $key['foreign_table'], $key['foreign_columns']]);
                $this->assertSame(DB::connection()->getDatabaseName(), $key['foreign_schema']);
                $this->assertSame('restrict', $key['on_delete']);
                $this->assertSame('restrict', $key['on_update']);
            }
        }
    }

    public function test_account_codes_are_unique_per_user_and_types_are_checked(): void
    {
        $user = User::factory()->create()->id;
        $this->account($user);
        $this->rejects(fn () => $this->account($user), 1062);
        $this->account(User::factory()->create()->id);
        foreach (['liability', 'equity', 'revenue', 'expense'] as $type) {
            $this->account($user, ['code' => $type, 'type' => $type]);
        }
        $this->rejects(fn () => $this->account($user, ['code' => 'invalid', 'type' => 'income']), 3819);
        $this->assertDatabaseCount('chart_of_accounts', 6);
    }

    #[DataProvider('nonCanonicalAccountTypes')]
    public function test_account_types_reject_noncanonical_case(string $type): void
    {
        $user = User::factory()->create()->id;
        $this->rejects(fn () => $this->account($user, ['type' => $type]), 3819);
        $this->assertDatabaseCount('chart_of_accounts', 0);
    }

    public static function nonCanonicalAccountTypes(): array
    {
        return [['ASSET'], ['Asset'], ['asset ']];
    }

    #[DataProvider('journalStatuses')]
    public function test_status_and_posting_timestamp_consistency(string $status, ?string $postedAt, bool $valid): void
    {
        $user = User::factory()->create()->id;
        $insert = fn () => $this->journal($user, ['status' => $status, 'posted_at' => $postedAt]);
        if ($valid) {
            $this->assertIsInt($insert());
        } else {
            $this->rejects($insert, 3819);
        }
    }

    public static function journalStatuses(): array
    {
        return [
            ['draft', null, true], ['posted', '2026-10-06 12:00:00.123456', true],
            ['draft', '2026-10-06 12:00:00', false], ['posted', null, false], ['void', null, false],
            ['DRAFT', null, false], ['Draft', null, false], ['draft ', null, false],
            ['POSTED', '2026-10-06 12:00:00', false], ['Posted', '2026-10-06 12:00:00', false],
        ];
    }

    #[DataProvider('lineSides')]
    public function test_line_side_and_number_checks(string $debit, string $credit, int $number, bool $valid): void
    {
        $user = User::factory()->create()->id;
        $account = $this->account($user);
        $journal = $this->journal($user);
        $insert = fn () => $this->line($user, $journal, $account, ['debit' => $debit, 'credit' => $credit, 'line_number' => $number]);
        if ($valid) {
            $id = $insert();
            $row = DB::table('journal_lines')->find($id);
            $this->assertSame([$debit, $credit], [$row->debit, $row->credit]);
        } else {
            $this->rejects($insert, 3819);
        }
    }

    public static function lineSides(): array
    {
        return [
            ['1.23', '0.00', 1, true], ['0.00', '1.23', 1, true],
            ['0.00', '0.00', 1, false], ['1.00', '1.00', 1, false],
            ['-1.00', '0.00', 1, false], ['0.00', '-1.00', 1, false], ['1.00', '0.00', 0, false],
        ];
    }

    public function test_line_numbers_are_unique_within_each_journal(): void
    {
        $user = User::factory()->create()->id;
        $account = $this->account($user);
        $journal = $this->journal($user);
        $this->line($user, $journal, $account);
        $this->rejects(fn () => $this->line($user, $journal, $account), 1062);
        $this->line($user, $journal, $account, ['line_number' => 2]);
        $this->line($user, $this->journal($user), $account);
        $this->assertDatabaseCount('journal_lines', 3);
    }

    public function test_parent_ownership_and_restricted_parent_deletion(): void
    {
        $user = User::factory()->create()->id;
        $parent = $this->account($user);
        $this->account($user, ['code' => 'child', 'parent_id' => $parent]);
        $other = User::factory()->create()->id;
        $this->rejects(fn () => $this->account($other, ['parent_id' => $parent]), 1452);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $parent)->delete(), 1451);
        $this->rejects(fn () => DB::table('users')->where('id', $user)->delete(), 1451);
        $this->assertDatabaseCount('chart_of_accounts', 2);
    }

    public function test_line_ownership_and_restrict_deletes(): void
    {
        $user = User::factory()->create()->id;
        $other = User::factory()->create()->id;
        $account = $this->account($user);
        $journal = $this->journal($user);
        $otherAccount = $this->account($other);
        $otherJournal = $this->journal($other);
        $this->rejects(fn () => $this->line($user, $otherJournal, $account), 1452);
        $this->rejects(fn () => $this->line($user, $journal, $otherAccount), 1452);
        $this->line($user, $journal, $account);
        $this->rejects(fn () => DB::table('journal_entries')->where('id', $journal)->delete(), 1451);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $account)->delete(), 1451);
        $this->rejects(fn () => DB::table('users')->where('id', $other)->delete(), 1451);
        $this->assertDatabaseCount('journal_lines', 1);
    }

    public function test_rollback_guards_refuse_nonempty_tables_without_ddl(): void
    {
        $user = User::factory()->create()->id;
        $account = $this->account($user);
        $journal = $this->journal($user);
        $this->line($user, $journal, $account);
        foreach (['chart_of_accounts', 'journal_entries', 'journal_lines'] as $offset => $table) {
            $number = $offset + 1;
            $migration = require database_path("migrations/2026_10_06_00000{$number}_create_{$table}_table.php");
            try {
                $migration->down();
                $this->fail('Expected non-empty rollback guard.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("Refusing to drop non-empty accounting table {$table}", $exception->getMessage());
            }
            $this->assertDatabaseCount($table, 1);
        }
    }

    public function test_existing_tables_are_not_silently_adopted_or_changed(): void
    {
        foreach (['chart_of_accounts', 'journal_entries', 'journal_lines'] as $offset => $table) {
            $before = Schema::getColumns($table);
            $number = $offset + 1;
            $migration = require database_path("migrations/2026_10_06_00000{$number}_create_{$table}_table.php");
            try {
                $migration->up();
                $this->fail('Expected explicit existing-table preflight.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('already exists', $exception->getMessage());
            }
            $this->assertSame($before, Schema::getColumns($table));
        }
    }
}
