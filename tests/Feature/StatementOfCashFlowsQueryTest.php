<?php

namespace Tests\Feature;

use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\ReviewCashAccountRole;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Accounting\Queries\StatementOfCashFlowsQuery;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class StatementOfCashFlowsQueryTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code, string $type, ?string $role): ChartAccount
    {
        $account = $owner->chartAccounts()->create(['code' => $code, 'name' => $code, 'type' => $type]);
        if ($role !== null) {
            (new ReviewCashAccountRole)->execute($owner, $account->id, $role);
        }

        return $account;
    }

    private function line(ChartAccount $account, string $debit, string $credit): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit];
    }

    private function allocation(int $debit, int $credit, string $amount, ?string $category): array
    {
        return ['debit_line_index' => $debit, 'credit_line_index' => $credit,
            'amount' => $amount, 'category' => $category];
    }

    private function journal(User $owner, string $date, array $lines, array $allocations = [], bool $post = true): JournalEntry
    {
        $draft = (new SaveJournalDraft)->execute($owner, $date, config('accounting.currency'), $lines,
            allocations: $allocations);

        return $post ? (new PostJournalEntry)->execute($owner, $draft->id) : $draft;
    }

    private function historicalPost(JournalEntry $draft, ?string $currency = null): void
    {
        DB::table('journal_entries')->where('id', $draft->id)->update([
            'status' => 'posted', 'posted_at' => now(),
            'currency' => $currency ?? $draft->currency,
        ]);
    }

    private function report(User $owner, string $start = '2026-01-01', string $end = '2026-01-31'): array
    {
        return (new StatementOfCashFlowsQuery)->execute($owner, $start, $end)->toArray();
    }

    private function reason(callable $read, AccountingConflictReason $expected): void
    {
        try {
            $read();
            $this->fail('Expected a typed cash-flow conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertSame($expected, $exception->reason);
        }
    }

    public function test_empty_owner_has_configured_currency_and_no_cash_accounts(): void
    {
        $report = $this->report(User::factory()->create());
        $this->assertSame(config('accounting.currency'), $report['currency']);
        $this->assertFalse($report['has_designated_cash_accounts']);
        foreach (['beginning_cash', 'opening_balance_adjustments', 'operating_activities',
            'investing_activities', 'financing_activities', 'net_cash_flow', 'net_change_in_cash',
            'ending_cash'] as $field) {
            $this->assertSame('0.00', $report[$field]);
        }
    }

    public function test_direct_activity_beginning_and_independent_ending_reconcile_exactly(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equity = $this->account($owner, '3000', 'equity', 'non_cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $asset = $this->account($owner, '1500', 'asset', 'non_cash');
        $debt = $this->account($owner, '2000', 'liability', 'non_cash');
        $this->journal($owner, '2025-12-31', [$this->line($cash, '100.01', '0'), $this->line($equity, '0', '100.01')],
            [$this->allocation(0, 1, '100.01', 'financing')]);
        $this->journal($owner, '2026-01-01', [$this->line($cash, '10.02', '0'), $this->line($revenue, '0', '10.02')],
            [$this->allocation(0, 1, '10.02', 'operating')]);
        $this->journal($owner, '2026-01-15', [$this->line($asset, '4.03', '0'), $this->line($cash, '0', '4.03')],
            [$this->allocation(0, 1, '4.03', 'investing')]);
        $this->journal($owner, '2026-01-31', [$this->line($cash, '2.04', '0'), $this->line($debt, '0', '2.04')],
            [$this->allocation(0, 1, '2.04', 'financing')]);
        $this->journal($owner, '2026-02-01', [$this->line($cash, '99.00', '0'), $this->line($revenue, '0', '99.00')],
            [$this->allocation(0, 1, '99.00', 'operating')]);
        $cash->update(['is_active' => false]);

        $report = $this->report($owner);
        $this->assertTrue($report['has_designated_cash_accounts']);
        $this->assertSame('100.01', $report['beginning_cash']);
        $this->assertSame('10.02', $report['operating_activities']);
        $this->assertSame('-4.03', $report['investing_activities']);
        $this->assertSame('2.04', $report['financing_activities']);
        $this->assertSame('8.03', $report['net_cash_flow']);
        $this->assertSame('108.04', $report['ending_cash']);
        $this->assertSame('0.00', $this->report($owner, '2026-01-02', '2026-01-02')['net_change_in_cash']);
    }

    public function test_cash_equivalent_transfer_is_excluded_and_negative_activity_is_exact(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equivalent = $this->account($owner, '1100', 'asset', 'cash_equivalent');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $this->journal($owner, '2026-01-01', [$this->line($equivalent, '10.01', '0'), $this->line($cash, '0', '10.01')],
            [$this->allocation(0, 1, '10.01', null)]);
        $this->journal($owner, '2026-01-31', [$this->line($expense, '0.02', '0'), $this->line($equivalent, '0', '0.02')],
            [$this->allocation(0, 1, '0.02', 'operating')]);
        $report = $this->report($owner);
        $this->assertSame('-0.02', $report['operating_activities']);
        $this->assertSame('-0.02', $report['ending_cash']);
        $this->assertSame('-0.02', $report['net_change_in_cash']);
    }

    public function test_ordinary_reversal_contributes_opposite_activity_on_its_own_date(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $original = $this->journal($owner, '2026-01-01', [
            $this->line($cash, '7.01', '0'), $this->line($revenue, '0', '7.01'),
        ], [$this->allocation(0, 1, '7.01', 'operating')]);
        $this->travelTo(Carbon::parse('2026-01-02 12:00:00'));
        try {
            (new ReverseJournalEntry)->execute($owner, $original->id);
        } finally {
            $this->travelBack();
        }
        $this->assertSame('7.01', $this->report($owner, '2026-01-01', '2026-01-01')['operating_activities']);
        $reversal = $this->report($owner, '2026-01-02', '2026-01-02');
        $this->assertSame('-7.01', $reversal['operating_activities']);
        $this->assertSame('7.01', $reversal['beginning_cash']);
        $this->assertSame('0.00', $reversal['ending_cash']);
    }

    public function test_sealed_reversal_with_different_activity_category_is_corrupt(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $original = $this->journal($owner, '2026-01-01', [
            $this->line($cash, '7.01', '0'), $this->line($revenue, '0', '7.01'),
        ], [$this->allocation(0, 1, '7.01', 'operating')]);

        // Build a valid but mismatched sealed reversal without weakening posted-row triggers.
        $reversal = $this->journal($owner, '2026-01-02', [
            $this->line($cash, '0', '7.01'), $this->line($revenue, '7.01', '0'),
        ], [$this->allocation(1, 0, '7.01', 'investing')], post: false);
        DB::table('journal_entries')->where('id', $reversal->id)
            ->update(['reversal_of_id' => $original->id]);
        (new PostJournalEntry)->execute($owner, $reversal->id);

        $this->reason(fn () => $this->report($owner, '2026-01-02', '2026-01-02'),
            AccountingConflictReason::CashFlowCorruptData);
    }

    public function test_sealed_reversal_with_different_line_pairing_is_corrupt(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equivalent = $this->account($owner, '1100', 'asset', 'cash_equivalent');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $otherRevenue = $this->account($owner, '4100', 'revenue', 'non_cash');
        $original = $this->journal($owner, '2026-01-01', [
            $this->line($cash, '5.00', '0'), $this->line($equivalent, '5.00', '0'),
            $this->line($revenue, '0', '5.00'), $this->line($otherRevenue, '0', '5.00'),
        ], [
            $this->allocation(0, 2, '5.00', 'operating'),
            $this->allocation(1, 3, '5.00', 'operating'),
        ]);

        $reversal = $this->journal($owner, '2026-01-02', [
            $this->line($cash, '0', '5.00'), $this->line($equivalent, '0', '5.00'),
            $this->line($revenue, '5.00', '0'), $this->line($otherRevenue, '5.00', '0'),
        ], [
            $this->allocation(2, 1, '5.00', 'operating'),
            $this->allocation(3, 0, '5.00', 'operating'),
        ], post: false);
        DB::table('journal_entries')->where('id', $reversal->id)
            ->update(['reversal_of_id' => $original->id]);
        (new PostJournalEntry)->execute($owner, $reversal->id);

        $this->reason(fn () => $this->report($owner, '2026-01-02', '2026-01-02'),
            AccountingConflictReason::CashFlowCorruptData);
    }

    public function test_one_journal_can_split_cash_across_activity_categories(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $asset = $this->account($owner, '1500', 'asset', 'non_cash');
        $debt = $this->account($owner, '2000', 'liability', 'non_cash');
        $this->journal($owner, '2026-01-15', [
            $this->line($expense, '1.01', '0'), $this->line($asset, '2.02', '0'),
            $this->line($debt, '3.03', '0'), $this->line($cash, '0', '6.06'),
        ], [
            $this->allocation(0, 3, '1.01', 'operating'),
            $this->allocation(1, 3, '2.02', 'investing'),
            $this->allocation(2, 3, '3.03', 'financing'),
        ]);
        $report = $this->report($owner);
        $this->assertSame('-1.01', $report['operating_activities']);
        $this->assertSame('-2.02', $report['investing_activities']);
        $this->assertSame('-3.03', $report['financing_activities']);
        $this->assertSame('-6.06', $report['ending_cash']);
    }

    public function test_opening_journal_and_its_reversal_are_adjustments_only(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equity = $this->account($owner, '3000', 'equity', 'non_cash');
        $draft = $this->journal($owner, '2026-01-01', [$this->line($cash, '5.01', '0'), $this->line($equity, '0', '5.01')], post: false);
        $this->historicalPost($draft);
        $batchId = DB::table('opening_balance_batches')->insertGetId([
            'user_id' => $owner->id, 'opening_date' => '2026-01-01', 'currency' => config('accounting.currency'),
        ]);
        DB::table('opening_balance_batches')->where('id', $batchId)->update([
            'status' => 'posted', 'journal_entry_id' => $draft->id, 'posted_at' => now(),
        ]);
        $this->assertSame('5.01', $this->report($owner, '2026-01-01', '2026-01-01')['opening_balance_adjustments']);
        $this->travelTo(Carbon::parse('2026-01-02 12:00:00'));
        try {
            (new ReverseJournalEntry)->execute($owner, $draft->id);
        } finally {
            $this->travelBack();
        }
        $report = $this->report($owner, '2026-01-01', '2026-01-02');
        $this->assertSame('0.00', $report['opening_balance_adjustments']);
        $this->assertSame('0.00', $report['net_cash_flow']);
        $this->assertSame('0.00', $report['ending_cash']);
    }

    public function test_cumulative_roles_and_period_scoped_completions(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equity = $this->account($owner, '3000', 'equity', 'non_cash');
        $unreviewed = $this->account($owner, '5000', 'expense', null);
        $past = $this->journal($owner, '2025-12-31', [$this->line($cash, '3.00', '0'), $this->line($equity, '0', '3.00')], post: false);
        $this->historicalPost($past);
        $this->assertSame('3.00', $this->report($owner)['beginning_cash']);
        $ordinary = $this->journal($owner, '2026-01-15', [$this->line($cash, '1.00', '0'), $this->line($equity, '0', '1.00')], post: false);
        $this->historicalPost($ordinary);
        $this->reason(fn () => $this->report($owner), AccountingConflictReason::CashFlowClassificationIncomplete);
        $this->reason(fn () => $this->report($owner, '2026-01-01', '2026-01-15'), AccountingConflictReason::CashFlowClassificationIncomplete);
        $future = $this->journal($owner, '2026-02-01', [$this->line($unreviewed, '1.00', '0'), $this->line($equity, '0', '1.00')], post: false);
        $this->historicalPost($future);
        $this->assertSame('3.00', $this->report($owner, '2026-01-01', '2026-01-14')['beginning_cash']);
        $this->reason(fn () => $this->report($owner, '2026-02-01', '2026-02-01'), AccountingConflictReason::CashFlowUnreviewedAccount);
    }

    public function test_pure_non_cash_posted_journal_needs_no_completion(): void
    {
        $owner = User::factory()->create();
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $payable = $this->account($owner, '2000', 'liability', 'non_cash');
        $draft = $this->journal($owner, '2026-01-15', [$this->line($expense, '1.00', '0'), $this->line($payable, '0', '1.00')], post: false);
        $this->historicalPost($draft);
        $this->assertSame('0.00', $this->report($owner)['net_cash_flow']);
    }

    public function test_currency_is_cumulative_case_sensitive_and_future_safe(): void
    {
        $owner = User::factory()->create();
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $equity = $this->account($owner, '3000', 'equity', 'non_cash');
        $first = $this->journal($owner, '2026-01-01', [$this->line($expense, '1.00', '0'), $this->line($equity, '0', '1.00')], post: false);
        $this->historicalPost($first, 'SAR');
        $this->assertSame('SAR', $this->report($owner)['currency']);
        $future = $this->journal($owner, '2026-02-01', [$this->line($equity, '1.00', '0'), $this->line($expense, '0', '1.00')], post: false);
        $this->historicalPost($future, 'USD');
        $this->assertSame('SAR', $this->report($owner)['currency']);
        $this->reason(fn () => $this->report($owner, '2026-01-01', '2026-02-01'), AccountingConflictReason::PostedLedgerCurrencyMismatch);

        $caseOwner = User::factory()->create();
        $caseExpense = $this->account($caseOwner, '5000', 'expense', 'non_cash');
        $caseEquity = $this->account($caseOwner, '3000', 'equity', 'non_cash');
        foreach (['SAR', 'sar'] as $index => $currency) {
            $draft = $this->journal($caseOwner, '2026-01-0'.($index + 1), [
                $this->line($caseExpense, '1.00', '0'), $this->line($caseEquity, '0', '1.00'),
            ], post: false);
            $this->historicalPost($draft, $currency);
        }
        $this->reason(fn () => $this->report($caseOwner), AccountingConflictReason::PostedLedgerCurrencyMismatch);
    }

    public function test_invalid_dates_and_owner_isolation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cash = $this->account($other, '1000', 'asset', 'cash');
        $equity = $this->account($other, '3000', 'equity', 'non_cash');
        $this->journal($other, '2026-01-01', [$this->line($cash, '9.00', '0'), $this->line($equity, '0', '9.00')],
            [$this->allocation(0, 1, '9.00', 'financing')]);
        $this->assertSame('0.00', $this->report($owner)['ending_cash']);
        try {
            $this->report($owner, '2026-02-01', '2026-01-01');
            $this->fail('Expected invalid range.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('end_date', $exception->errors());
        }
    }

    public function test_fiscal_close_is_excluded_and_cash_in_fiscal_close_is_corruption(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equity = $this->account($owner, '3000', 'equity', 'non_cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $close = $this->journal($owner, '2026-01-31', [
            $this->line($revenue, '1.00', '0'), $this->line($expense, '0', '1.00'),
        ]);
        $cashClose = $this->journal($owner, '2026-01-31', [
            $this->line($cash, '1.00', '0'), $this->line($equity, '0', '1.00'),
        ], post: false);
        DB::table('fiscal_year_closes')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $equity->id,
            'journal_entry_id' => $close->id, 'closed_at' => now(),
        ]);
        $this->assertSame('0.00', $this->report($owner)['net_cash_flow']);

        $this->historicalPost($cashClose);
        DB::table('fiscal_year_closes')->insert([
            'user_id' => $owner->id, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31',
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $equity->id,
            'journal_entry_id' => $cashClose->id, 'closed_at' => now(),
        ]);
        $this->reason(fn () => $this->report($owner), AccountingConflictReason::CashFlowCorruptData);
    }

    public function test_sealed_malformed_allocation_is_corruption_not_incomplete_classification(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $draft = $this->journal($owner, '2026-01-15', [
            $this->line($cash, '1.00', '0'), $this->line($revenue, '0', '1.00'),
        ], post: false);
        $this->historicalPost($draft);
        DB::table('journal_line_allocations')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $draft->id,
            'debit_line_id' => $draft->lines[0]->id, 'credit_line_id' => $draft->lines[1]->id,
            'amount' => '1.00', 'category' => null,
        ]);
        DB::table('cash_flow_journal_completions')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $draft->id, 'completed_at' => now(),
        ]);
        $this->reason(fn () => $this->report($owner), AccountingConflictReason::CashFlowCorruptData);
    }

    public function test_offsetting_individually_unbalanced_posted_journals_are_rejected(): void
    {
        $owner = User::factory()->create();
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $payable = $this->account($owner, '2000', 'liability', 'non_cash');
        $debitOnly = $this->journal($owner, '2026-01-01', [$this->line($expense, '1.00', '0')], post: false);
        $creditOnly = $this->journal($owner, '2026-01-01', [$this->line($payable, '0', '1.00')], post: false);
        $this->historicalPost($debitOnly);
        $this->historicalPost($creditOnly);

        $this->reason(fn () => $this->report($owner), AccountingConflictReason::PostedLedgerUnbalanced);
    }

    public function test_large_decimal_aggregates_and_empty_period_keep_exact_cents(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equity = $this->account($owner, '3000', 'equity', 'non_cash');
        foreach (['2026-01-01', '2026-01-02'] as $date) {
            $this->journal($owner, $date, [
                $this->line($cash, '9999999999999.99', '0'),
                $this->line($equity, '0', '9999999999999.99'),
            ], [$this->allocation(0, 1, '9999999999999.99', 'financing')]);
        }
        $report = $this->report($owner);
        $this->assertSame('19999999999999.98', $report['financing_activities']);
        $this->assertSame('19999999999999.98', $report['ending_cash']);
        $empty = $this->report($owner, '2026-01-03', '2026-01-31');
        $this->assertSame('19999999999999.98', $empty['beginning_cash']);
        $this->assertSame('0.00', $empty['net_change_in_cash']);
        $this->assertSame('19999999999999.98', $empty['ending_cash']);
    }

    public function test_query_uses_a_nonlocking_transaction_snapshot(): void
    {
        $owner = User::factory()->create();
        $levels = [];
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::listen(function ($event) use (&$levels): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select')) {
                $levels[] = DB::transactionLevel();
            }
        });
        try {
            $this->report($owner);
            $reads = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower(ltrim($query['query'])), 'select'));
            $this->assertNotEmpty($reads);
            $this->assertNotEmpty($levels);
            $this->assertTrue(collect($levels)->every(fn ($level) => $level > 0));
            foreach ($reads as $read) {
                $this->assertStringNotContainsString('for update', strtolower($read['query']));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
