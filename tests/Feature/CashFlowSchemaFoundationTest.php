<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class CashFlowSchemaFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function rejects(callable $operation, int $code): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[1]);
        }
    }

    private function journal(int $userId): int
    {
        return DB::table('journal_entries')->insertGetId([
            'user_id' => $userId, 'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
    }

    private function line(int $userId, int $journalId, int $accountId, int $number, string $side): int
    {
        return DB::table('journal_lines')->insertGetId([
            'user_id' => $userId, 'journal_entry_id' => $journalId, 'chart_account_id' => $accountId,
            'line_number' => $number, 'debit' => $side === 'debit' ? '10.00' : '0.00',
            'credit' => $side === 'credit' ? '10.00' : '0.00',
        ]);
    }

    public function test_cash_role_is_nullable_without_default_and_case_sensitive(): void
    {
        $column = collect(Schema::getColumns('chart_of_accounts'))->firstWhere('name', 'cash_role');
        $this->assertSame('varchar(16)', $column['type']);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);

        $owner = User::factory()->create();
        $account = $owner->chartAccounts()->create(['code' => '1000', 'name' => 'Existing account', 'type' => 'asset']);
        $this->assertNull($account->fresh()->cash_role);
        foreach (['non_cash', 'cash', 'cash_equivalent', null] as $role) {
            DB::table('chart_of_accounts')->where('id', $account->id)->update(['cash_role' => $role]);
            $this->assertSame($role, $account->fresh()->cash_role);
        }
        foreach (['CASH', 'Cash', 'cash ', 'unknown', ''] as $invalid) {
            $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $account->id)->update(['cash_role' => $invalid]), 3819);
        }
        $liability = $owner->chartAccounts()->create(['code' => '2000', 'name' => 'Payable', 'type' => 'liability']);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $liability->id)->update(['cash_role' => 'cash']), 3819);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $liability->id)->update(['cash_role' => 'cash_equivalent']), 3819);
        DB::table('chart_of_accounts')->where('id', $liability->id)->update(['cash_role' => 'non_cash']);
    }

    public function test_line_composite_unique_key_and_allocation_foreign_keys_are_exact(): void
    {
        $lineKeys = collect(Schema::getIndexes('journal_lines'))->keyBy('name');
        $this->assertSame(['user_id', 'journal_entry_id', 'id'], $lineKeys['jl_owner_entry_id_unique']['columns']);
        $this->assertTrue($lineKeys['jl_owner_entry_id_unique']['unique']);

        $allocationIndexes = collect(Schema::getIndexes('journal_line_allocations'))->keyBy('name');
        foreach ([
            'jla_journal_owner_index' => ['journal_entry_id', 'user_id'],
            'jla_debit_owner_entry_index' => ['user_id', 'journal_entry_id', 'debit_line_id'],
            'jla_credit_owner_entry_index' => ['user_id', 'journal_entry_id', 'credit_line_id'],
        ] as $name => $columns) {
            $this->assertSame($columns, $allocationIndexes[$name]['columns']);
        }

        $keys = collect(Schema::getForeignKeys('journal_line_allocations'))->keyBy('name');
        foreach ([
            'jla_journal_owner_fk' => [['journal_entry_id', 'user_id'], 'journal_entries', ['id', 'user_id']],
            'jla_debit_owner_entry_fk' => [['user_id', 'journal_entry_id', 'debit_line_id'], 'journal_lines', ['user_id', 'journal_entry_id', 'id']],
            'jla_credit_owner_entry_fk' => [['user_id', 'journal_entry_id', 'credit_line_id'], 'journal_lines', ['user_id', 'journal_entry_id', 'id']],
        ] as $name => $expected) {
            $this->assertSame($expected, [$keys[$name]['columns'], $keys[$name]['foreign_table'], $keys[$name]['foreign_columns']]);
            $this->assertSame('restrict', $keys[$name]['on_delete']);
            $this->assertSame('restrict', $keys[$name]['on_update']);
        }
    }

    public function test_allocation_columns_checks_and_same_owner_journal_links(): void
    {
        $columns = collect(Schema::getColumns('journal_line_allocations'))->keyBy('name');
        foreach ([
            'id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false],
            'journal_entry_id' => ['bigint unsigned', false], 'debit_line_id' => ['bigint unsigned', false],
            'credit_line_id' => ['bigint unsigned', false], 'amount' => ['decimal(15,2)', false],
            'category' => ['varchar(16)', true],
        ] as $name => [$type, $nullable]) {
            $this->assertSame([$type, $nullable], [$columns[$name]['type'], $columns[$name]['nullable']]);
        }

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $owner->chartAccounts()->create(['code' => '1000', 'name' => 'Account', 'type' => 'asset']);
        $otherAccount = $other->chartAccounts()->create(['code' => '1000', 'name' => 'Other', 'type' => 'asset']);
        $journal = $this->journal($owner->id);
        $secondJournal = $this->journal($owner->id);
        $foreignJournal = $this->journal($other->id);
        $debit = $this->line($owner->id, $journal, $account->id, 1, 'debit');
        $credit = $this->line($owner->id, $journal, $account->id, 2, 'credit');
        $otherJournalCredit = $this->line($owner->id, $secondJournal, $account->id, 1, 'credit');
        $foreignCredit = $this->line($other->id, $foreignJournal, $otherAccount->id, 1, 'credit');
        $insert = fn (array $changes = []) => DB::table('journal_line_allocations')->insertGetId(array_replace([
            'user_id' => $owner->id, 'journal_entry_id' => $journal,
            'debit_line_id' => $debit, 'credit_line_id' => $credit,
            'amount' => '0.01', 'category' => null,
        ], $changes));

        foreach (['operating', 'investing', 'financing', null] as $category) {
            $this->assertIsInt($insert(['category' => $category]));
        }
        foreach (['OPERATING', 'Operating', 'other', ''] as $category) {
            $this->rejects(fn () => $insert(['category' => $category]), 3819);
        }
        $this->rejects(fn () => $insert(['credit_line_id' => $debit]), 3819);
        $this->rejects(fn () => $insert(['amount' => '0.00']), 3819);
        $this->rejects(fn () => $insert(['amount' => '-0.01']), 3819);
        $this->rejects(fn () => $insert(['credit_line_id' => $otherJournalCredit]), 1452);
        $this->rejects(fn () => $insert(['credit_line_id' => $foreignCredit]), 1452);
        $this->rejects(fn () => $insert(['user_id' => $other->id]), 1452);
    }

    public function test_completion_is_unique_owner_scoped_and_timestamped(): void
    {
        $columns = collect(Schema::getColumns('cash_flow_journal_completions'))->keyBy('name');
        $this->assertSame(['datetime(6)', false], [$columns['completed_at']['type'], $columns['completed_at']['nullable']]);
        $index = collect(Schema::getIndexes('cash_flow_journal_completions'))->keyBy('name')['cfjc_journal_owner_unique'];
        $this->assertSame(['journal_entry_id', 'user_id'], $index['columns']);
        $this->assertTrue($index['unique']);
        $foreign = collect(Schema::getForeignKeys('cash_flow_journal_completions'))->keyBy('name')['cfjc_journal_owner_fk'];
        $this->assertSame([['journal_entry_id', 'user_id'], 'journal_entries', ['id', 'user_id']],
            [$foreign['columns'], $foreign['foreign_table'], $foreign['foreign_columns']]);
        $this->assertSame('restrict', $foreign['on_delete']);
        $this->assertSame('restrict', $foreign['on_update']);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $journal = $this->journal($owner->id);
        $account = $owner->chartAccounts()->create(['code' => '1000', 'name' => 'Account', 'type' => 'asset']);
        $this->line($owner->id, $journal, $account->id, 1, 'debit');
        $this->line($owner->id, $journal, $account->id, 2, 'credit');
        $insert = fn (int $userId, int $journalId) => DB::table('cash_flow_journal_completions')->insertGetId([
            'user_id' => $userId, 'journal_entry_id' => $journalId, 'completed_at' => '2026-10-09 12:00:00.123456',
        ]);
        $this->rejects(fn () => $insert($owner->id, $journal), 1644);
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()]);
        $id = $insert($owner->id, $journal);
        $this->assertSame('2026-10-09 12:00:00.123456', DB::table('cash_flow_journal_completions')->find($id)->completed_at);
        $this->rejects(fn () => $insert($owner->id, $journal), 1062);
        $this->rejects(fn () => $insert($other->id, $journal), 1452);
    }
}
