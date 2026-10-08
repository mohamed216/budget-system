<?php

namespace Tests\Feature;

use App\Accounting\FiscalYearCloseOverlap;
use App\Models\ChartAccount;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FiscalYearCloseFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code = '3000'): ChartAccount
    {
        return $owner->chartAccounts()->create(['code' => $code, 'name' => 'Retained earnings', 'type' => 'equity', 'is_active' => true]);
    }

    private function journal(User $owner, string $date = '2026-12-31'): JournalEntry
    {
        $journal = new JournalEntry(['entry_date' => $date, 'currency' => config('accounting.currency')]);
        $journal->user_id = $owner->id;
        $journal->save();

        return $journal;
    }

    private function close(User $owner, ChartAccount $account, ?JournalEntry $journal = null, string $start = '2026-04-01', string $end = '2027-03-31'): int
    {
        return DB::table('fiscal_year_closes')->insertGetId([
            'user_id' => $owner->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $account->id,
            'journal_entry_id' => $journal?->id, 'closed_at' => '2027-04-01 12:34:56.123456',
        ]);
    }

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject an invalid fiscal-year close.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    public function test_schema_columns_indexes_checks_and_restrictive_composite_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_year_closes'));
        $columns = collect(Schema::getColumns('fiscal_year_closes'))->keyBy('name');
        foreach ([
            'id' => ['bigint unsigned', false, null],
            'user_id' => ['bigint unsigned', false, null],
            'start_date' => ['date', false, null],
            'end_date' => ['date', false, null],
            'currency' => ['char(3)', false, null],
            'retained_earnings_account_id' => ['bigint unsigned', false, null],
            'journal_entry_id' => ['bigint unsigned', true, null],
            'closed_at' => ['datetime(6)', false, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
        ] as $name => [$type, $nullable, $default]) {
            $this->assertSame($type, $columns[$name]['type']);
            $this->assertSame($nullable, $columns[$name]['nullable']);
            $this->assertSame($default, $columns[$name]['default']);
        }

        $schema = DB::connection()->getDatabaseName();
        $indexes = collect(DB::select('SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$schema, 'fiscal_year_closes']))
            ->groupBy('INDEX_NAME')->map(fn ($rows) => $rows->pluck('COLUMN_NAME')->all());
        foreach ([
            'PRIMARY' => ['id'],
            'fyc_journal_unique' => ['journal_entry_id'],
            'fyc_owner_start_end_index' => ['user_id', 'start_date', 'end_date', 'id'],
            'fyc_owner_end_start_index' => ['user_id', 'end_date', 'start_date', 'id'],
            'fyc_account_owner_index' => ['retained_earnings_account_id', 'user_id'],
            'fyc_journal_owner_index' => ['journal_entry_id', 'user_id'],
        ] as $name => $expected) {
            $this->assertSame($expected, $indexes[$name]);
        }
        $this->assertTrue(DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $schema)
            ->where('TABLE_NAME', 'fiscal_year_closes')->where('CONSTRAINT_NAME', 'fyc_dates_check')->where('CONSTRAINT_TYPE', 'CHECK')->exists());
        foreach (['fyc_user_fk' => 'users', 'fyc_account_owner_fk' => 'chart_of_accounts', 'fyc_journal_owner_fk' => 'journal_entries'] as $name => $table) {
            $fk = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $schema)
                ->where('CONSTRAINT_NAME', $name)->first();
            $this->assertSame($table, $fk->REFERENCED_TABLE_NAME);
            $this->assertSame('RESTRICT', $fk->DELETE_RULE);
            $this->assertSame('RESTRICT', $fk->UPDATE_RULE);
        }
        $columnsByConstraint = collect(DB::select('SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME IN (?, ?) ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', [$schema, 'fiscal_year_closes', 'fyc_account_owner_fk', 'fyc_journal_owner_fk']))
            ->groupBy('CONSTRAINT_NAME')->map(fn ($rows) => $rows->pluck('COLUMN_NAME')->all());
        $this->assertSame(['retained_earnings_account_id', 'user_id'], $columnsByConstraint['fyc_account_owner_fk']);
        $this->assertSame(['journal_entry_id', 'user_id'], $columnsByConstraint['fyc_journal_owner_fk']);
    }

    public function test_date_check_same_owner_links_unique_journal_and_restrictive_deletes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner);
        $foreignAccount = $this->account($other);
        $journal = $this->journal($owner);
        $foreignJournal = $this->journal($other);
        $id = $this->close($owner, $account, $journal);
        $zeroActivityId = $this->close($owner, $account, null, '2027-04-01', '2027-04-01');
        $this->assertNull(DB::table('fiscal_year_closes')->where('id', $zeroActivityId)->value('journal_entry_id'));
        $this->rejects(fn () => $this->close($owner, $account, null, '2027-05-02', '2027-05-01'), 3819);
        $this->rejects(fn () => $this->close($owner, $foreignAccount, null, '2028-01-01', '2028-12-31'), 1452);
        $this->rejects(fn () => $this->close($owner, $account, $foreignJournal, '2028-01-01', '2028-12-31'), 1452);
        $this->rejects(fn () => $this->close($owner, $account, $journal, '2028-01-01', '2028-12-31'), 1062);
        $this->rejects(fn () => DB::table('users')->where('id', $owner->id)->delete(), 1451);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $account->id)->delete(), 1451);
        $this->rejects(fn () => DB::table('journal_entries')->where('id', $journal->id)->delete(), 1451);
        $this->assertDatabaseCount('fiscal_year_closes', 2);
        $this->assertNotNull(FiscalYearClose::findOrFail($id));
    }

    public function test_model_owner_scope_casts_relations_and_mass_assignment_protection(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner);
        $journal = $this->journal($owner);
        $id = $this->close($owner, $account, $journal);
        $this->close($other, $this->account($other));
        $close = FiscalYearClose::ownedBy($owner)->firstOrFail();
        $this->assertSame([$id], FiscalYearClose::ownedBy($owner)->pluck('id')->all());
        $this->assertSame($owner->id, $close->user->id);
        $this->assertSame($id, $owner->fiscalYearCloses()->firstOrFail()->id);
        $this->assertSame($account->id, $close->retainedEarningsAccount->id);
        $this->assertSame($id, $account->fiscalYearClosesAsRetainedEarnings()->firstOrFail()->id);
        $this->assertSame($journal->id, $close->journalEntry->id);
        $this->assertSame($id, $journal->fiscalYearClose->id);
        $this->assertSame('2026-04-01', $close->start_date->format('Y-m-d'));
        $this->assertSame('2027-03-31', $close->end_date->format('Y-m-d'));
        $this->assertSame('2027-04-01 12:34:56.123456', $close->closed_at->format('Y-m-d H:i:s.u'));
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $close->closed_at);
        $close->fill(['user_id' => $other->id, 'retained_earnings_account_id' => $this->account($other, '3100')->id,
            'journal_entry_id' => $this->journal($other)->id, 'closed_at' => now(),
            'start_date' => '2026-04-02']);
        $this->assertSame($owner->id, $close->user_id);
        $this->assertSame($account->id, $close->retained_earnings_account_id);
        $this->assertSame($journal->id, $close->journal_entry_id);
        $this->assertSame('2027-04-01 12:34:56.123456', $close->closed_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-04-02', $close->start_date->format('Y-m-d'));
    }

    public function test_overlap_is_inclusive_owner_scoped_adjacent_and_excludable(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->close($owner, $this->account($owner));
        $overlap = new FiscalYearCloseOverlap;
        foreach ([
            ['2026-04-01', '2027-03-31'], ['2026-03-01', '2026-04-01'],
            ['2027-03-31', '2027-04-30'], ['2026-05-01', '2026-05-31'],
            ['2025-01-01', '2028-01-01'],
        ] as [$start, $end]) {
            $this->assertTrue($overlap->exists($owner, $start, $end));
        }
        $this->assertFalse($overlap->exists($owner, '2026-03-01', '2026-03-31'));
        $this->assertFalse($overlap->exists($owner, '2027-04-01', '2027-04-30'));
        $this->assertFalse($overlap->exists($other, '2026-04-01', '2027-03-31'));
        $this->assertFalse($overlap->exists($owner, '2026-04-01', '2027-03-31', $id));
    }

    public function test_overlap_locking_reads_actual_fiscal_year_row(): void
    {
        $owner = User::factory()->create();
        $this->close($owner, $this->account($owner));
        $queries = [];
        DB::listen(function ($event) use (&$queries): void { $queries[] = $event->sql; });
        DB::transaction(function () use ($owner): void {
            $this->assertTrue((new FiscalYearCloseOverlap)->exists($owner, '2026-04-01', '2026-04-01', lockForUpdate: true));
        });
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains(strtolower($sql), 'fiscal_year_closes')
            && str_contains(strtolower($sql), 'for update') && ! str_contains(strtolower($sql), 'exists')));
    }

    public function test_existing_table_is_not_adopted_and_rollback_refuses_data(): void
    {
        $migration = require database_path('migrations/2026_10_08_000004_create_fiscal_year_closes_table.php');
        $columns = Schema::getColumns('fiscal_year_closes');
        try {
            $migration->up();
            $this->fail('Existing table must not be adopted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertSame($columns, Schema::getColumns('fiscal_year_closes'));
        $owner = User::factory()->create();
        $this->close($owner, $this->account($owner));
        try {
            $migration->down();
            $this->fail('Non-empty fiscal-year-close table must not be dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to drop non-empty', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('fiscal_year_closes'));
        $this->assertDatabaseCount('fiscal_year_closes', 1);
    }
}
