<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class CashFlowTriggerFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected cash-flow immutability trigger to reject the mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
    }

    public function test_draft_allocations_are_editable_and_historical_posted_insert_is_sealable(): void
    {
        $owner = User::factory()->create();
        $account = $owner->chartAccounts()->create(['code' => '1000', 'name' => 'Account', 'type' => 'asset']);
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
        $debit = DB::table('journal_lines')->insertGetId([
            'user_id' => $owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $account->id,
            'line_number' => 1, 'debit' => '10.00', 'credit' => '0.00',
        ]);
        $credit = DB::table('journal_lines')->insertGetId([
            'user_id' => $owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $account->id,
            'line_number' => 2, 'debit' => '0.00', 'credit' => '10.00',
        ]);
        $allocation = [
            'user_id' => $owner->id, 'journal_entry_id' => $journal,
            'debit_line_id' => $debit, 'credit_line_id' => $credit,
            'amount' => '1.00', 'category' => null,
        ];

        $draftId = DB::table('journal_line_allocations')->insertGetId($allocation);
        $this->assertSame(1, DB::table('journal_line_allocations')->where('id', $draftId)->update(['amount' => '2.00']));
        $this->assertSame(1, DB::table('journal_line_allocations')->where('id', $draftId)->delete());

        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()]);
        $postedId = DB::table('journal_line_allocations')->insertGetId($allocation);
        $this->rejects(fn () => DB::table('journal_line_allocations')->where('id', $postedId)->update(['amount' => '2.00']));
        $this->rejects(fn () => DB::table('journal_line_allocations')->where('id', $postedId)->delete());
        $completion = DB::table('cash_flow_journal_completions')->insertGetId([
            'user_id' => $owner->id, 'journal_entry_id' => $journal,
            'completed_at' => '2026-10-09 12:00:00.123456',
        ]);
        $this->rejects(fn () => DB::table('journal_line_allocations')->insert($allocation));
        $this->rejects(fn () => DB::table('cash_flow_journal_completions')->where('id', $completion)->update(['completed_at' => now()]));
        $this->rejects(fn () => DB::table('cash_flow_journal_completions')->where('id', $completion)->delete());
        $this->assertDatabaseCount('journal_line_allocations', 1);
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
    }
}
