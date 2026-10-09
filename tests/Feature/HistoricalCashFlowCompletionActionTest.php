<?php

namespace Tests\Feature;

use App\Accounting\Actions\CompleteHistoricalCashFlowJournal;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\HistoricalCashFlowCompletionOutcome;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class HistoricalCashFlowCompletionActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code, string $role, string $type = 'asset'): ChartAccount
    {
        $account = $owner->chartAccounts()->create(['code' => $code, 'name' => $code, 'type' => $type]);
        DB::table('chart_of_accounts')->where('id', $account->id)->update(['cash_role' => $role]);

        return $account->fresh();
    }

    /** @param array<int, array{0: ChartAccount, 1: string, 2: string}> $specs */
    private function journal(User $owner, array $specs, ?int $reversalOf = null): array
    {
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
            'reversal_of_id' => $reversalOf,
        ]);
        $ids = [];
        foreach ($specs as $index => [$account, $side, $amount]) {
            $ids[] = DB::table('journal_lines')->insertGetId([
                'user_id' => $owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $account->id,
                'line_number' => $index + 1,
                'debit' => $side === 'debit' ? $amount : '0.00',
                'credit' => $side === 'credit' ? $amount : '0.00',
            ]);
        }
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()]);

        return [$journal, $ids];
    }

    private function row(int $debit, int $credit, string $amount, ?string $category): array
    {
        return ['debit_line_id' => $debit, 'credit_line_id' => $credit, 'amount' => $amount, 'category' => $category];
    }

    private function conflicts(AccountingConflictReason $reason, User $owner, int $journal, array $rows): void
    {
        try {
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, $rows);
            $this->fail('Expected a historical allocation conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_exact_split_allocation_seals_once_and_retry_conflicts(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        $asset = $this->account($owner, '1500', 'non_cash');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '60.01'], [$asset, 'debit', '39.99'], [$cash, 'credit', '100.00'],
        ]);
        $rows = [$this->row($ids[0], $ids[2], '60.01', 'operating'),
            $this->row($ids[1], $ids[2], '39.99', 'investing')];
        $action = new CompleteHistoricalCashFlowJournal;
        $this->assertSame(HistoricalCashFlowCompletionOutcome::Completed, $action->execute($owner, $journal, $rows));
        $this->assertDatabaseCount('journal_line_allocations', 2);
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
        $saved = DB::table('journal_line_allocations')->orderBy('id')->get();
        $this->assertSame(['60.01', '39.99'], $saved->pluck('amount')->all());
        try {
            $action->execute($owner, $journal, $rows);
            $this->fail('Expected a stable already-completed conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowAlreadyCompleted, $exception->reason);
        }
        $this->assertDatabaseCount('journal_line_allocations', 2);
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
    }

    public function test_multiple_cash_lines_and_internal_transfer_are_supported(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $equivalent = $this->account($owner, '1100', 'cash_equivalent');
        $revenue = $this->account($owner, '4000', 'non_cash', 'revenue');
        [$journal, $ids] = $this->journal($owner, [
            [$cash, 'debit', '70.00'], [$equivalent, 'debit', '30.00'],
            [$equivalent, 'credit', '40.00'], [$revenue, 'credit', '60.00'],
        ]);
        $rows = [$this->row($ids[0], $ids[2], '40.00', null),
            $this->row($ids[0], $ids[3], '30.00', 'operating'),
            $this->row($ids[1], $ids[3], '30.00', 'operating')];
        $this->assertSame(HistoricalCashFlowCompletionOutcome::Completed,
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, $rows));
        $this->assertDatabaseCount('journal_line_allocations', 3);
    }

    public function test_matching_unsealed_posted_allocations_can_be_sealed_without_reinsertion(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        [$journal, $ids] = $this->journal($owner, [[$expense, 'debit', '1.00'], [$cash, 'credit', '1.00']]);
        DB::table('journal_line_allocations')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $journal,
            'debit_line_id' => $ids[0], 'credit_line_id' => $ids[1],
            'amount' => '1.00', 'category' => 'operating',
        ]);
        $this->assertSame(HistoricalCashFlowCompletionOutcome::Completed,
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal,
                [$this->row($ids[0], $ids[1], '1.00', 'operating')]));
        $this->assertDatabaseCount('journal_line_allocations', 1);
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
    }

    public function test_different_unsealed_allocations_cannot_be_replaced_or_sealed(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        $asset = $this->account($owner, '1500', 'non_cash');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '60.00'], [$asset, 'debit', '40.00'], [$cash, 'credit', '100.00'],
        ]);
        foreach ([
            $this->row($ids[0], $ids[2], '60.00', 'operating'),
            $this->row($ids[1], $ids[2], '40.00', 'investing'),
        ] as $row) {
            DB::table('journal_line_allocations')->insert($row + [
                'user_id' => $owner->id, 'journal_entry_id' => $journal,
            ]);
        }
        $before = DB::table('journal_line_allocations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        try {
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, [
                $this->row($ids[0], $ids[2], '60.00', 'financing'),
                $this->row($ids[1], $ids[2], '40.00', 'investing'),
            ]);
            $this->fail('Expected mismatched immutable allocations to conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowInvalidAllocation, $exception->reason);
        }

        $this->assertSame($before, DB::table('journal_line_allocations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('journal_line_allocations', 2);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_reordered_equivalent_unsealed_allocations_seal_without_reinsertion(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        $asset = $this->account($owner, '1500', 'non_cash');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '60.00'], [$asset, 'debit', '40.00'], [$cash, 'credit', '100.00'],
        ]);
        $rows = [
            $this->row($ids[0], $ids[2], '60.00', 'operating'),
            $this->row($ids[1], $ids[2], '40.00', 'investing'),
        ];
        foreach ($rows as $row) {
            DB::table('journal_line_allocations')->insert($row + [
                'user_id' => $owner->id, 'journal_entry_id' => $journal,
            ]);
        }
        $before = DB::table('journal_line_allocations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->assertSame(HistoricalCashFlowCompletionOutcome::Completed,
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, array_reverse($rows)));
        $this->assertSame($before, DB::table('journal_line_allocations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('journal_line_allocations', 2);
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
    }

    public function test_database_failure_after_first_allocation_insert_rolls_back_every_write(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        $asset = $this->account($owner, '1500', 'non_cash');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '60.00'], [$asset, 'debit', '40.00'], [$cash, 'credit', '100.00'],
        ]);
        $injectedAfterInsert = false;
        DB::listen(function ($event) use (&$injectedAfterInsert, $owner, $journal): void {
            if ($injectedAfterInsert || $event->connectionName !== 'mysql_testing'
                || ! str_starts_with(strtolower(ltrim($event->sql)), 'insert into')
                || ! str_contains($event->sql, 'journal_line_allocations')) {
                return;
            }
            $injectedAfterInsert = true;
            // Seal on the same connection after the first insert. The existing DB trigger rejects the next insert.
            DB::table('cash_flow_journal_completions')->insert([
                'user_id' => $owner->id, 'journal_entry_id' => $journal,
                'completed_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        });

        try {
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, [
                $this->row($ids[0], $ids[2], '60.00', 'operating'),
                $this->row($ids[1], $ids[2], '40.00', 'investing'),
            ]);
            $this->fail('Expected the second insert to hit the sealed-allocation trigger.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Sealed cash-flow allocations cannot be changed', $exception->getMessage());
        }

        $this->assertTrue($injectedAfterInsert, 'The failure must occur after a successful allocation insert.');
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_pure_non_cash_journal_needs_no_completion(): void
    {
        $owner = User::factory()->create();
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        $payable = $this->account($owner, '2000', 'non_cash', 'liability');
        [$journal] = $this->journal($owner, [[$expense, 'debit', '100.00'], [$payable, 'credit', '100.00']]);
        $this->assertSame(HistoricalCashFlowCompletionOutcome::NoCashLines,
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, []));
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
        $this->assertDatabaseCount('journal_line_allocations', 0);
    }

    public function test_unreviewed_role_blocks_and_no_cash_journal_rejects_rows(): void
    {
        $owner = User::factory()->create();
        $cash = $owner->chartAccounts()->create(['code' => '1000', 'name' => 'Unreviewed', 'type' => 'asset']);
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        [$journal, $ids] = $this->journal($owner, [[$expense, 'debit', '1.00'], [$cash, 'credit', '1.00']]);
        $this->conflicts(AccountingConflictReason::CashFlowUnreviewedAccount, $owner, $journal,
            [$this->row($ids[0], $ids[1], '1.00', 'operating')]);
        DB::table('chart_of_accounts')->where('id', $cash->id)->update(['cash_role' => 'non_cash']);
        $this->conflicts(AccountingConflictReason::CashFlowNoCashLines, $owner, $journal,
            [$this->row($ids[0], $ids[1], '1.00', 'operating')]);
    }

    public function test_categories_and_coverage_are_exact(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $otherCash = $this->account($owner, '1100', 'cash_equivalent');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '50.00'], [$otherCash, 'debit', '50.00'], [$cash, 'credit', '100.00'],
        ]);
        $valid = [$this->row($ids[0], $ids[2], '50.00', 'operating'), $this->row($ids[1], $ids[2], '50.00', null)];
        $invalid = [
            [$this->row($ids[0], $ids[2], '50.00', null), $valid[1]],
            [$valid[0], $this->row($ids[1], $ids[2], '50.00', 'financing')],
            [$this->row($ids[0], $ids[2], '49.99', 'operating'), $valid[1]],
            [$this->row($ids[0], $ids[2], '50.01', 'operating'), $valid[1]],
            [$valid[0], $this->row($ids[1], $ids[2], '50.01', null)],
            [$valid[0]],
        ];
        foreach ($invalid as $rows) {
            $this->conflicts(AccountingConflictReason::CashFlowInvalidAllocation, $owner, $journal, $rows);
        }
        $this->assertSame(HistoricalCashFlowCompletionOutcome::Completed,
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal, $valid));
    }

    public function test_cross_journal_owner_same_side_and_non_cash_pairing_are_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        $revenue = $this->account($owner, '4000', 'non_cash', 'revenue');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '10.00'], [$cash, 'credit', '10.00'],
        ]);
        [, $foreignIds] = $this->journal($other, [
            [$this->account($other, '2000', 'non_cash', 'liability'), 'debit', '10.00'],
            [$this->account($other, '1000', 'cash'), 'credit', '10.00'],
        ]);
        [, $otherIds] = $this->journal($owner, [[$expense, 'debit', '10.00'], [$revenue, 'credit', '10.00']]);
        foreach ([
            [$this->row($otherIds[0], $ids[1], '10.00', 'operating')],
            [$this->row($foreignIds[0], $ids[1], '10.00', 'operating')],
            [$this->row($ids[1], $ids[1], '10.00', 'operating')],
            [$this->row($ids[0], $otherIds[0], '10.00', 'operating')],
        ] as $rows) {
            $this->conflicts(AccountingConflictReason::CashFlowInvalidAllocation, $owner, $journal, $rows);
        }

        $asset = $this->account($owner, '1500', 'non_cash');
        $liability = $this->account($owner, '2000', 'non_cash', 'liability');
        [$mixedJournal, $mixedIds] = $this->journal($owner, [
            [$expense, 'debit', '10.00'], [$asset, 'debit', '5.00'],
            [$cash, 'credit', '10.00'], [$liability, 'credit', '5.00'],
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowInvalidAllocation, $owner, $mixedJournal, [
            $this->row($mixedIds[0], $mixedIds[2], '10.00', 'operating'),
            $this->row($mixedIds[1], $mixedIds[3], '5.00', 'investing'),
        ]);
    }

    public function test_counterpart_capacity_and_cash_overallocation_are_rejected(): void
    {
        $owner = User::factory()->create();
        $cashA = $this->account($owner, '1000', 'cash');
        $cashB = $this->account($owner, '1100', 'cash_equivalent');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        [$journal, $ids] = $this->journal($owner, [
            [$expense, 'debit', '100.00'], [$cashA, 'credit', '60.00'], [$cashB, 'credit', '40.00'],
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowInvalidAllocation, $owner, $journal, [
            $this->row($ids[0], $ids[1], '60.00', 'operating'),
            $this->row($ids[0], $ids[2], '40.00', 'operating'),
            $this->row($ids[0], $ids[2], '0.01', 'operating'),
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowInvalidAllocation, $owner, $journal, [
            $this->row($ids[0], $ids[1], '60.00', 'operating'),
            $this->row($ids[0], $ids[2], '39.99', 'operating'),
        ]);

        $asset = $this->account($owner, '1500', 'non_cash');
        [$mixedJournal, $mixedIds] = $this->journal($owner, [
            [$expense, 'debit', '60.00'], [$asset, 'debit', '40.00'],
            [$cashA, 'credit', '50.00'], [$cashB, 'credit', '50.00'],
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowInvalidAllocation, $owner, $mixedJournal, [
            $this->row($mixedIds[0], $mixedIds[2], '50.00', 'operating'),
            $this->row($mixedIds[0], $mixedIds[3], '11.00', 'operating'),
            $this->row($mixedIds[1], $mixedIds[3], '39.00', 'investing'),
        ]);
    }

    public function test_invalid_money_and_shape_are_validation_errors_without_partial_writes(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        [$journal, $ids] = $this->journal($owner, [[$expense, 'debit', '1.00'], [$cash, 'credit', '1.00']]);
        foreach ([1.0, '1.001', '1e0', '-1.00', '0.00'] as $amount) {
            try {
                (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal,
                    [['debit_line_id' => $ids[0], 'credit_line_id' => $ids[1], 'amount' => $amount, 'category' => 'operating']]);
                $this->fail('Expected exact-money validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allocations.0.amount', $exception->errors());
            }
        }
        foreach (['OPERATING', 'other'] as $category) {
            try {
                (new CompleteHistoricalCashFlowJournal)->execute($owner, $journal,
                    [['debit_line_id' => $ids[0], 'credit_line_id' => $ids[1], 'amount' => '1.00', 'category' => $category]]);
                $this->fail('Expected category validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allocations.0.category', $exception->errors());
            }
        }
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_foreign_draft_opening_close_and_reversal_journals_are_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cash = $this->account($owner, '1000', 'cash');
        $expense = $this->account($owner, '5000', 'non_cash', 'expense');
        [$journal, $ids] = $this->journal($owner, [[$expense, 'debit', '1.00'], [$cash, 'credit', '1.00']]);
        [$foreign] = $this->journal($other, [
            [$this->account($other, '5000', 'non_cash', 'expense'), 'debit', '1.00'],
            [$this->account($other, '1000', 'cash'), 'credit', '1.00'],
        ]);
        try {
            (new CompleteHistoricalCashFlowJournal)->execute($owner, $foreign, []);
            $this->fail('Expected foreign journal to be hidden.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('cash_flow_journal_completions', 0);
        }

        $draft = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowJournalNotPosted, $owner, $draft, []);

        $opening = DB::table('opening_balance_batches')->insertGetId([
            'user_id' => $owner->id, 'opening_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
        DB::table('opening_balance_batches')->where('id', $opening)->update([
            'status' => 'posted', 'journal_entry_id' => $journal, 'posted_at' => now(),
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowSpecialJournal, $owner, $journal,
            [$this->row($ids[0], $ids[1], '1.00', 'operating')]);

        $retained = $this->account($owner, '3000', 'non_cash', 'equity');
        [$closing, $closingIds] = $this->journal($owner, [[$expense, 'debit', '1.00'], [$retained, 'credit', '1.00']]);
        DB::table('fiscal_year_closes')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $retained->id,
            'journal_entry_id' => $closing, 'closed_at' => now(),
        ]);
        $this->conflicts(AccountingConflictReason::CashFlowSpecialJournal, $owner, $closing,
            [$this->row($closingIds[0], $closingIds[1], '1.00', 'operating')]);

        [$reversal] = $this->journal($owner, [[$cash, 'debit', '1.00'], [$expense, 'credit', '1.00']], $journal);
        $this->conflicts(AccountingConflictReason::CashFlowReversalRequiresInheritance, $owner, $reversal, []);
    }
}
