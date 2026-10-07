<?php

namespace Tests\Feature;

use App\Accounting\OpeningBalanceInput;
use App\Models\ChartAccount;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceLine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OpeningBalanceFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function batch(User $owner, array $extra = []): int
    {
        return DB::table('opening_balance_batches')->insertGetId(array_replace([
            'user_id' => $owner->id,
            'opening_date' => '2026-01-01',
            'currency' => config('accounting.currency'),
        ], $extra));
    }

    private function account(User $owner, string $code): ChartAccount
    {
        return $owner->chartAccounts()->create([
            'code' => $code, 'name' => $code, 'type' => 'asset', 'is_active' => false,
        ]);
    }

    private function line(int $batchId, User $owner, ChartAccount $account, array $extra = []): int
    {
        return DB::table('opening_balance_lines')->insertGetId(array_replace([
            'batch_id' => $batchId, 'user_id' => $owner->id,
            'chart_account_id' => $account->id, 'debit' => '0.01', 'credit' => '0.00',
        ], $extra));
    }

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject the invalid opening balance row.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    public function test_schema_types_defaults_indexes_checks_and_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('opening_balance_batches'));
        $this->assertTrue(Schema::hasTable('opening_balance_lines'));
        foreach ([
            'opening_balance_batches' => [
                'id' => ['bigint unsigned', false, null],
                'user_id' => ['bigint unsigned', false, null],
                'opening_date' => ['date', false, null],
                'currency' => ['char(3)', false, null],
                'status' => ['varchar(16)', false, 'draft'],
                'journal_entry_id' => ['bigint unsigned', true, null],
                'posted_at' => ['datetime(6)', true, null],
                'created_at' => ['timestamp', true, null],
                'updated_at' => ['timestamp', true, null],
            ],
            'opening_balance_lines' => [
                'id' => ['bigint unsigned', false, null],
                'user_id' => ['bigint unsigned', false, null],
                'batch_id' => ['bigint unsigned', false, null],
                'chart_account_id' => ['bigint unsigned', false, null],
                'debit' => ['decimal(15,2)', false, '0.00'],
                'credit' => ['decimal(15,2)', false, '0.00'],
                'created_at' => ['timestamp', true, null],
                'updated_at' => ['timestamp', true, null],
            ],
        ] as $table => $expected) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($expected as $name => [$type, $nullable, $default]) {
                $this->assertSame($type, $columns[$name]['type']);
                $this->assertSame($nullable, $columns[$name]['nullable']);
                $this->assertSame($default, $columns[$name]['default']);
            }
        }

        $schema = DB::connection()->getDatabaseName();
        foreach ([
            'opening_balance_batches' => [
                'obb_id_owner_unique' => ['id', 'user_id'],
                'obb_journal_unique' => ['journal_entry_id'],
                'obb_owner_status_date_index' => ['user_id', 'status', 'opening_date', 'id'],
                'obb_journal_owner_index' => ['journal_entry_id', 'user_id'],
            ],
            'opening_balance_lines' => [
                'obl_batch_account_unique' => ['batch_id', 'chart_account_id'],
                'obl_batch_owner_index' => ['batch_id', 'user_id'],
                'obl_account_owner_index' => ['chart_account_id', 'user_id'],
            ],
        ] as $table => $expected) {
            $indexes = collect(DB::select('SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$schema, $table]))
                ->groupBy('INDEX_NAME')->map(fn ($rows) => $rows->pluck('COLUMN_NAME')->all());
            foreach ($expected as $name => $columns) {
                $this->assertSame($columns, $indexes[$name]);
            }
        }

        foreach (['obb_status_check', 'obb_posted_link_check', 'obl_side_check'] as $name) {
            $this->assertTrue(DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $schema)->where('CONSTRAINT_NAME', $name)
                ->where('CONSTRAINT_TYPE', 'CHECK')->exists());
        }
        foreach (['obb_user_fk', 'obb_journal_owner_fk', 'obl_batch_owner_fk', 'obl_account_owner_fk'] as $name) {
            $foreignKey = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $schema)->where('CONSTRAINT_NAME', $name)->first();
            $this->assertNotNull($foreignKey);
            $this->assertSame('RESTRICT', $foreignKey->UPDATE_RULE);
            $this->assertSame('RESTRICT', $foreignKey->DELETE_RULE);
        }
    }

    public function test_defaults_exact_sides_duplicate_account_and_owner_foreign_keys(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner, '101');
        $otherAccount = $this->account($other, '201');
        $batchId = $this->batch($owner);
        $this->assertSame('draft', DB::table('opening_balance_batches')->where('id', $batchId)->value('status'));
        $this->assertNull(DB::table('opening_balance_batches')->where('id', $batchId)->value('journal_entry_id'));
        $this->assertNull(DB::table('opening_balance_batches')->where('id', $batchId)->value('posted_at'));
        $lineId = $this->line($batchId, $owner, $account);
        $this->assertSame('0.01', DB::table('opening_balance_lines')->where('id', $lineId)->value('debit'));
        $this->assertSame('0.00', DB::table('opening_balance_lines')->where('id', $lineId)->value('credit'));
        $maximumAccount = $this->account($owner, '102');
        $maximumId = $this->line($batchId, $owner, $maximumAccount, ['debit' => '0.00', 'credit' => '9999999999999.99']);
        $this->assertSame('9999999999999.99', DB::table('opening_balance_lines')->where('id', $maximumId)->value('credit'));
        $this->rejects(fn () => $this->line($batchId, $owner, $account), 1062);
        $this->rejects(fn () => $this->line($batchId, $owner, $otherAccount), 1452);
        $this->rejects(fn () => $this->line($batchId, $other, $otherAccount), 1452);
        $this->rejects(fn () => $this->line($batchId + 1000, $owner, $account), 1452);
        $this->rejects(fn () => $this->batch($owner, ['user_id' => $owner->id + 100000]), 1452);
        $this->rejects(fn () => DB::table('users')->where('id', $owner->id)->delete(), 1451);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $account->id)->delete(), 1451);
    }

    public function test_check_constraints_reject_invalid_sides_and_batch_states(): void
    {
        $owner = User::factory()->create();
        $batchId = $this->batch($owner);
        $account = $this->account($owner, '101');
        foreach ([
            ['0.00', '0.00'], ['0.01', '0.01'], ['-0.01', '0.00'],
        ] as [$debit, $credit]) {
            $this->rejects(fn () => $this->line($batchId, $owner, $account, ['debit' => $debit, 'credit' => $credit]), 3819);
        }
        $this->rejects(fn () => $this->batch($owner, ['status' => 'POSTED']), 3819);
        $this->rejects(fn () => $this->batch($owner, ['status' => 'posted']), 3819);
        $this->rejects(fn () => $this->batch($owner, ['posted_at' => '2026-01-01 00:00:00.000001']), 3819);
    }

    public function test_same_owner_journal_link_is_unique_and_cross_owner_link_is_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $journalId = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-01-01', 'currency' => config('accounting.currency'),
        ]);
        $otherBatchId = $this->batch($other);
        $this->rejects(fn () => DB::table('opening_balance_batches')->where('id', $otherBatchId)->update([
            'status' => 'posted', 'journal_entry_id' => $journalId, 'posted_at' => '2026-01-01 00:00:00.000001',
        ]), 1452);
        $batchId = $this->batch($owner);
        DB::table('opening_balance_batches')->where('id', $batchId)->update([
            'status' => 'posted', 'journal_entry_id' => $journalId, 'posted_at' => '2026-01-01 00:00:00.000001',
        ]);
        $this->assertSame($journalId, DB::table('opening_balance_batches')->where('id', $batchId)->value('journal_entry_id'));
        $secondId = $this->batch($owner);
        $this->rejects(fn () => DB::table('opening_balance_batches')->where('id', $secondId)->update([
            'status' => 'posted', 'journal_entry_id' => $journalId, 'posted_at' => '2026-01-01 00:00:00.000001',
        ]), 1062);
        $this->rejects(fn () => DB::table('journal_entries')->where('id', $journalId)->delete(), 1451);
    }

    public function test_models_relationships_scopes_casts_and_protected_fields(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner, '101');
        $batch = $owner->openingBalanceBatches()->create([
            'opening_date' => '2026-01-01', 'currency' => config('accounting.currency'),
            'status' => 'posted', 'journal_entry_id' => 123, 'posted_at' => now(), 'user_id' => $other->id,
        ]);
        $this->assertTrue($batch->isDraft());
        $this->assertFalse($batch->isPosted());
        $this->assertSame($owner->id, $batch->user->id);
        $this->assertSame('2026-01-01', $batch->opening_date->format('Y-m-d'));
        $this->assertNull($batch->journal_entry_id);
        $this->assertNull($batch->posted_at);
        $this->assertSame([$batch->id], OpeningBalanceBatch::ownedBy($owner)->pluck('id')->all());
        $this->assertSame([], OpeningBalanceBatch::ownedBy($other)->pluck('id')->all());
        $line = new OpeningBalanceLine;
        $line->fill(['chart_account_id' => $account->id, 'debit' => '0.01', 'credit' => '0.00',
            'user_id' => $other->id, 'batch_id' => $batch->id]);
        $line->user_id = $owner->id;
        $batch->lines()->save($line);
        $this->assertSame('0.01', $line->fresh()->debit);
        $this->assertSame('0.00', $line->fresh()->credit);
        $this->assertTrue($line->batch->is($batch));
        $this->assertTrue($line->chartAccount->is($account));
        $this->assertSame(1, $account->openingBalanceLines()->count());
        $this->assertSame([$line->id], OpeningBalanceLine::ownedBy($owner)->pluck('id')->all());
        $this->assertFalse($batch->isFillable('user_id'));
        $this->assertFalse($batch->isFillable('status'));
        $this->assertFalse($batch->isFillable('journal_entry_id'));
        $this->assertFalse($batch->isFillable('posted_at'));
        $this->assertFalse($line->isFillable('user_id'));
        $this->assertFalse($line->isFillable('batch_id'));
    }

    public function test_rollback_guards_preserve_nonempty_tables_and_parent_dependencies(): void
    {
        $owner = User::factory()->create();
        $batchId = $this->batch($owner);
        $batchMigration = require database_path('migrations/2026_10_08_000001_create_opening_balance_batches_table.php');
        $lineMigration = require database_path('migrations/2026_10_08_000002_create_opening_balance_lines_table.php');
        try {
            $batchMigration->down();
            $this->fail('Expected nonempty batch guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to drop non-empty', $exception->getMessage());
        }
        try {
            $lineMigration->down();
            $this->fail('Expected batch rows to protect the empty line table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('opening-balance rows exist', $exception->getMessage());
        }
        $account = $this->account($owner, '101');
        $this->line($batchId, $owner, $account);
        try {
            $lineMigration->down();
            $this->fail('Expected nonempty line guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('opening-balance rows exist', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('opening_balance_batches'));
        $this->assertTrue(Schema::hasTable('opening_balance_lines'));
    }

    public function test_input_helper_requires_exact_strings_unique_owned_accounts_and_valid_header(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner, '101');
        $foreign = $this->account($other, '201');
        $this->assertSame(['opening_date' => '2026-01-01', 'currency' => config('accounting.currency')],
            OpeningBalanceInput::header('2026-01-01', config('accounting.currency')));
        $this->assertSame([['chart_account_id' => $account->id, 'debit' => '0.01', 'credit' => '0.00']],
            OpeningBalanceInput::lines($owner, [[
                'chart_account_id' => $account->id, 'debit' => '0.01', 'credit' => '0',
            ]]));
        $this->assertSame([['chart_account_id' => $account->id, 'debit' => '0.00', 'credit' => '9999999999999.99']],
            OpeningBalanceInput::lines($owner, [[
                'chart_account_id' => $account->id, 'debit' => '0', 'credit' => '9999999999999.99',
            ]]));
        $this->assertSame([], OpeningBalanceInput::lines($owner, []));
        foreach ([
            [['chart_account_id' => $account->id, 'debit' => 0.01, 'credit' => '0']],
            [['chart_account_id' => $account->id, 'debit' => '0', 'credit' => '0']],
            [['chart_account_id' => $account->id, 'debit' => '1', 'credit' => '1']],
            [['chart_account_id' => $account->id, 'debit' => '10000000000000.00', 'credit' => '0']],
            [['chart_account_id' => $foreign->id, 'debit' => '1', 'credit' => '0']],
            [['chart_account_id' => $account->id, 'debit' => '1', 'credit' => '0'],
                ['chart_account_id' => $account->id, 'debit' => '0', 'credit' => '1']],
        ] as $invalid) {
            try {
                OpeningBalanceInput::lines($owner, $invalid);
                $this->fail('Expected opening balance validation to reject invalid lines.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        foreach ([['2026-02-30', config('accounting.currency')], ['2026-01-01', 'usd']] as [$date, $currency]) {
            try {
                OpeningBalanceInput::header($date, $currency);
                $this->fail('Expected opening balance validation to reject invalid header.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
