<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodOverlap;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingPeriodFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function period(User $owner, string $start, string $end, array $other = []): int
    {
        return DB::table('accounting_periods')->insertGetId(array_replace([
            'user_id' => $owner->id, 'start_date' => $start, 'end_date' => $end,
        ], $other));
    }

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject the invalid period record.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    public function test_schema_columns_defaults_indexes_checks_and_owner_foreign_key(): void
    {
        $this->assertTrue(Schema::hasTable('accounting_periods'));
        $columns = collect(Schema::getColumns('accounting_periods'))->keyBy('name');
        foreach ([
            'id' => ['bigint unsigned', false, null],
            'user_id' => ['bigint unsigned', false, null],
            'start_date' => ['date', false, null],
            'end_date' => ['date', false, null],
            'status' => ['varchar(16)', false, 'open'],
            'first_closed_at' => ['datetime(6)', true, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
        ] as $name => [$type, $nullable, $default]) {
            $this->assertSame($type, $columns[$name]['type']);
            $this->assertSame($nullable, $columns[$name]['nullable']);
            $this->assertSame($default, $columns[$name]['default']);
        }

        $indexes = collect(DB::select('SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [DB::connection()->getDatabaseName(), 'accounting_periods']))
            ->groupBy('INDEX_NAME')->map(fn ($rows) => $rows->pluck('COLUMN_NAME')->all());
        $this->assertSame(['id'], $indexes['PRIMARY']);
        $this->assertSame(['user_id', 'start_date', 'end_date'], $indexes['ap_owner_dates_index']);
        $this->assertSame(['user_id', 'status', 'start_date'], $indexes['ap_owner_status_start_index']);

        $checks = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'accounting_periods')->where('CONSTRAINT_TYPE', 'CHECK')
            ->pluck('CONSTRAINT_NAME')->all();
        $this->assertContains('ap_dates_check', $checks);
        $this->assertContains('ap_status_check', $checks);

        $foreignKey = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('CONSTRAINT_NAME', 'ap_user_fk')->first();
        $this->assertSame('users', $foreignKey->REFERENCED_TABLE_NAME);
        $this->assertSame('RESTRICT', $foreignKey->UPDATE_RULE);
        $this->assertSame('RESTRICT', $foreignKey->DELETE_RULE);
    }

    public function test_checks_default_and_foreign_key_reject_invalid_rows(): void
    {
        $owner = User::factory()->create();
        $id = $this->period($owner, '2026-01-01', '2026-01-31');
        $this->assertSame('open', DB::table('accounting_periods')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('accounting_periods')->where('id', $id)->value('first_closed_at'));

        $this->rejects(fn () => $this->period($owner, '2026-02-02', '2026-02-01'), 3819);
        $this->rejects(fn () => $this->period($owner, '2026-02-01', '2026-02-28', ['status' => 'CLOSED']), 3819);
        $this->rejects(fn () => DB::table('accounting_periods')->where('id', $id)->update(['status' => 'invalid']), 3819);
        $this->rejects(fn () => DB::table('accounting_periods')->insert([
            'user_id' => $owner->id + 100000, 'start_date' => '2026-02-01', 'end_date' => '2026-02-28',
        ]), 1452);
        $this->rejects(fn () => DB::table('users')->where('id', $owner->id)->delete(), 1451);
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_model_scopes_relationships_casts_helpers_and_protected_fields(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $period = $owner->accountingPeriods()->create(['start_date' => '2026-03-01', 'end_date' => '2026-03-31']);
        $this->period($other, '2026-03-01', '2026-03-31');

        $this->assertSame([$period->id], AccountingPeriod::ownedBy($owner)->pluck('id')->all());
        $this->assertSame($owner->id, $period->user->id);
        $this->assertSame(1, $owner->accountingPeriods()->count());
        $this->assertSame('2026-03-01', $period->start_date->format('Y-m-d'));
        $this->assertSame('2026-03-31', $period->end_date->format('Y-m-d'));
        $this->assertTrue($period->isOpen());
        $this->assertFalse($period->isClosed());
        $this->assertFalse($period->wasEverClosed());

        $period->fill(['user_id' => $other->id, 'status' => 'closed', 'first_closed_at' => now(),
            'start_date' => '2026-03-02', 'end_date' => '2026-03-30']);
        $this->assertSame($owner->id, $period->user_id);
        $this->assertTrue($period->isOpen());
        $this->assertFalse($period->wasEverClosed());
        $this->assertSame('2026-03-02', $period->start_date->format('Y-m-d'));

        DB::table('accounting_periods')->where('id', $period->id)->update([
            'status' => 'closed', 'first_closed_at' => '2026-03-05 12:34:56.123456',
        ]);
        $period->refresh();
        $this->assertTrue($period->isClosed());
        $this->assertFalse($period->isOpen());
        $this->assertTrue($period->wasEverClosed());
        $this->assertSame('2026-03-05 12:34:56.123456', $period->first_closed_at->format('Y-m-d H:i:s.u'));
    }

    public function test_inclusive_overlap_boundaries_owner_scope_and_exclusion(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->period($owner, '2026-04-10', '2026-04-20');
        $overlap = new AccountingPeriodOverlap;

        foreach ([
            ['2026-04-10', '2026-04-20'], // exact
            ['2026-04-05', '2026-04-15'], // partial left
            ['2026-04-15', '2026-04-25'], // partial right
            ['2026-04-12', '2026-04-18'], // contained
            ['2026-04-01', '2026-04-30'], // containing
            ['2026-04-01', '2026-04-10'], // shared start boundary
            ['2026-04-20', '2026-04-25'], // shared end boundary
        ] as [$start, $end]) {
            $this->assertTrue($overlap->exists($owner, $start, $end), "Expected overlap for {$start} to {$end}");
        }
        $this->assertFalse($overlap->exists($owner, '2026-04-01', '2026-04-09'));
        $this->assertFalse($overlap->exists($owner, '2026-04-21', '2026-04-30'));
        $this->assertFalse($overlap->exists($other, '2026-04-10', '2026-04-20'));
        $this->assertFalse($overlap->exists($owner, '2026-04-10', '2026-04-20', $id));
    }

    public function test_locking_overlap_read_uses_for_update_inside_transaction(): void
    {
        $owner = User::factory()->create();
        $this->period($owner, '2026-05-01', '2026-05-31');
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        DB::transaction(function () use ($owner): void {
            $this->assertTrue((new AccountingPeriodOverlap)->exists($owner, '2026-05-15', '2026-05-16', lockForUpdate: true));
        });

        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains(strtolower($sql), 'accounting_periods')
            && str_contains(strtolower($sql), 'for update')));
    }

    public function test_rollback_refuses_any_period_row_and_existing_table_is_not_adopted(): void
    {
        $migration = require database_path('migrations/2026_10_07_000002_create_accounting_periods_table.php');
        $columns = Schema::getColumns('accounting_periods');
        try {
            $migration->up();
            $this->fail('Expected existing table preflight.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertSame($columns, Schema::getColumns('accounting_periods'));

        $owner = User::factory()->create();
        $this->period($owner, '2026-06-01', '2026-06-30');
        try {
            $migration->down();
            $this->fail('Expected non-empty rollback guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to drop non-empty accounting table accounting_periods', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('accounting_periods'));
        $this->assertDatabaseCount('accounting_periods', 1);
    }
}
