<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\CloseFiscalYear;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\IncomeStatementQuery;
use App\Accounting\Queries\StatementOfFinancialPositionQuery;
use App\Accounting\Queries\TrialBalanceQuery;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FinancialStatementQueryTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    private function account(string $code, string $type, ?User $owner = null): ChartAccount
    {
        return (new CreateChartAccount)->execute($owner ?? $this->owner, $code, 'Account '.$code, $type);
    }

    private function line(ChartAccount $account, string $debit, string $credit): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit];
    }

    private function journal(string $date, array $lines, bool $post = true, ?User $owner = null): JournalEntry
    {
        $owner ??= $this->owner;
        $draft = (new SaveJournalDraft)->execute($owner, $date, config('accounting.currency'), $lines);

        return $post ? (new PostJournalEntry)->execute($owner, $draft->id) : $draft;
    }

    private function revenueJournal(string $date, string $amount, ChartAccount $asset, ChartAccount $revenue, bool $post = true, ?User $owner = null): JournalEntry
    {
        return $this->journal($date, [$this->line($asset, $amount, '0'), $this->line($revenue, '0', $amount)], $post, $owner);
    }

    private function assertCurrencyConflict(callable $query): void
    {
        try {
            $query();
            $this->fail('Expected a mixed-currency reporting conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertSame('Financial report cannot combine journals with different currencies.', $exception->getMessage());
        }
    }

    public function test_income_statement_is_owner_scoped_posted_only_inclusive_exact_and_keeps_inactive_accounts(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->revenueJournal('2025-12-31', '99', $asset, $revenue);
        $this->revenueJournal('2026-01-01', '10.03', $asset, $revenue);
        $this->journal('2026-01-31', [$this->line($expense, '4.02', '0'), $this->line($asset, '0', '4.02')]);
        $this->revenueJournal('2026-02-01', '99', $asset, $revenue);
        $this->revenueJournal('2026-01-15', '999', $asset, $revenue, false);
        $foreignAsset = $this->account('1000', 'asset', $this->other);
        $foreignRevenue = $this->account('4000', 'revenue', $this->other);
        $this->revenueJournal('2026-01-15', '999', $foreignAsset, $foreignRevenue, owner: $this->other);
        $expense->update(['is_active' => false]);

        $report = (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-31');
        $this->assertSame(['date_from' => '2026-01-01', 'date_to' => '2026-01-31'], $report['period']);
        $this->assertSame(config('accounting.currency'), $report['currency']);
        $this->assertSame([['id' => $revenue->id, 'code' => '4000', 'name' => 'Account 4000', 'is_active' => true, 'amount' => '10.03']], $report['revenue_accounts']);
        $this->assertSame([['id' => $expense->id, 'code' => '5000', 'name' => 'Account 5000', 'is_active' => false, 'amount' => '4.02']], $report['expense_accounts']);
        $this->assertSame(['total_revenue' => '10.03', 'total_expense' => '4.02', 'net_profit_loss' => '6.01'], $report['totals']);
    }

    public function test_income_statement_keeps_contra_signs_and_exact_net_loss(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->journal('2026-01-01', [$this->line($revenue, '0.01', '0'), $this->line($asset, '0', '0.01')]);
        $this->journal('2026-01-01', [$this->line($expense, '0.02', '0'), $this->line($asset, '0', '0.02')]);

        $report = (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-01');
        $this->assertSame('-0.01', $report['revenue_accounts'][0]['amount']);
        $this->assertSame('0.02', $report['expense_accounts'][0]['amount']);
        $this->assertSame(['total_revenue' => '-0.01', 'total_expense' => '0.02', 'net_profit_loss' => '-0.03'], $report['totals']);
        $position = (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-01');
        $this->assertSame('-0.03', $position['totals']['assets']);
        $this->assertSame('-0.03', $position['totals']['unclosed_cumulative_profit_loss']);
        $this->assertSame('-0.03', $position['totals']['liabilities_equity_and_unclosed_profit_loss']);
        $this->assertSame('0.00', $position['totals']['equation_difference']);
    }

    public function test_position_statement_signs_and_unclosed_profit_reconcile_with_trial_balance(): void
    {
        $asset = $this->account('1000', 'asset');
        $liability = $this->account('2000', 'liability');
        $equity = $this->account('3000', 'equity');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->journal('2026-01-01', [
            $this->line($asset, '100.01', '0'),
            $this->line($liability, '0', '40.00'),
            $this->line($equity, '0', '60.01'),
        ]);
        $this->revenueJournal('2026-01-31', '10.00', $asset, $revenue);
        $this->journal('2026-01-31', [$this->line($expense, '3.00', '0'), $this->line($asset, '0', '3.00')]);
        $this->revenueJournal('2026-02-01', '999', $asset, $revenue);
        $liability->update(['is_active' => false]);
        $foreignAsset = $this->account('1000', 'asset', $this->other);
        $foreignEquity = $this->account('3000', 'equity', $this->other);
        $this->journal('2026-01-15', [$this->line($foreignAsset, '999', '0'), $this->line($foreignEquity, '0', '999')], owner: $this->other);

        $report = (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-31');
        $this->assertSame('2026-01-31', $report['as_of']);
        $this->assertSame('107.01', $report['assets'][0]['amount']);
        $this->assertSame('40.00', $report['liabilities'][0]['amount']);
        $this->assertFalse($report['liabilities'][0]['is_active']);
        $this->assertSame('60.01', $report['equity'][0]['amount']);
        $this->assertSame(['assets' => '107.01', 'liabilities' => '40.00', 'equity' => '60.01',
            'unclosed_cumulative_profit_loss' => '7.00',
            'liabilities_equity_and_unclosed_profit_loss' => '107.01', 'equation_difference' => '0.00'], $report['totals']);
        $trial = (new TrialBalanceQuery)->execute($this->owner, '2026-01-31');
        $byCode = array_column($trial['accounts'], null, 'code');
        $this->assertSame($byCode['1000']['signed_net'], $report['assets'][0]['amount']);
        $this->assertSame('-40.00', $byCode['2000']['signed_net']);
        $this->assertSame('-60.01', $byCode['3000']['signed_net']);
        $this->assertSame($trial['totals']['total_debit_balances'], $trial['totals']['total_credit_balances']);
    }

    public function test_reversal_uses_its_own_date_for_range_and_position_cutoff(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $original = $this->revenueJournal('2026-01-05', '5.00', $asset, $revenue);
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        try {
            (new ReverseJournalEntry)->execute($this->owner, $original->id);
        } finally {
            $this->travelBack();
        }

        $income = new IncomeStatementQuery;
        $position = new StatementOfFinancialPositionQuery;
        $this->assertSame('5.00', $income->execute($this->owner, '2026-01-05', '2026-01-31')['totals']['net_profit_loss']);
        $this->assertSame('-5.00', $income->execute($this->owner, '2026-02-01', '2026-02-01')['totals']['net_profit_loss']);
        $this->assertSame('0.00', $income->execute($this->owner, '2026-02-02', '2026-02-28')['totals']['net_profit_loss']);
        $this->assertSame('0.00', $income->execute($this->owner, '2026-01-01', '2026-02-01')['totals']['net_profit_loss']);
        $this->assertSame('0.00', $position->execute($this->owner, '2026-01-04')['totals']['assets']);
        $this->assertSame('5.00', $position->execute($this->owner, '2026-01-31')['totals']['assets']);
        $this->assertSame('0.00', $position->execute($this->owner, '2026-02-01')['totals']['assets']);
    }

    public function test_position_preserves_contrary_asset_liability_and_equity_signs(): void
    {
        $asset = $this->account('1000', 'asset');
        $liability = $this->account('2000', 'liability');
        $equity = $this->account('3000', 'equity');
        $this->journal('2026-01-01', [$this->line($liability, '0.01', '0'), $this->line($equity, '0', '0.01')]);
        $this->journal('2026-01-02', [$this->line($equity, '0.02', '0'), $this->line($asset, '0', '0.02')]);

        $report = (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-02');
        $this->assertSame('-0.02', $report['assets'][0]['amount']);
        $this->assertSame('-0.01', $report['liabilities'][0]['amount']);
        $this->assertSame('-0.01', $report['equity'][0]['amount']);
        $this->assertSame('0.00', $report['totals']['equation_difference']);
    }

    public function test_empty_ledgers_and_zero_activity_use_configured_currency_and_zero_strings(): void
    {
        $income = (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-31');
        $position = (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-31');
        $this->assertSame(config('accounting.currency'), $income['currency']);
        $this->assertSame(config('accounting.currency'), $position['currency']);
        $this->assertSame([], $income['revenue_accounts']);
        $this->assertSame([], $position['assets']);
        $this->assertSame(['total_revenue' => '0.00', 'total_expense' => '0.00', 'net_profit_loss' => '0.00'], $income['totals']);
        $this->assertSame('0.00', $position['totals']['equation_difference']);

        $this->account('4000', 'revenue');
        $this->assertSame('0.00', (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-31')['revenue_accounts'][0]['amount']);
    }

    public function test_actual_historical_currency_is_reported_and_mixed_currency_is_rejected(): void
    {
        $configured = config('accounting.currency');
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        try {
            config(['accounting.currency' => 'SAR']);
            $this->revenueJournal('2026-01-01', '1.00', $asset, $revenue);
            config(['accounting.currency' => 'USD']);
            $this->assertSame('SAR', (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-01')['currency']);
            $this->assertSame('SAR', (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-01')['currency']);
            $foreignAsset = $this->account('1000', 'asset', $this->other);
            $foreignRevenue = $this->account('4000', 'revenue', $this->other);
            $this->revenueJournal('2026-01-01', '999.00', $foreignAsset, $foreignRevenue, owner: $this->other);
            $this->assertSame('SAR', (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-01')['currency']);
            $this->revenueJournal('2026-01-02', '2.00', $asset, $revenue);
            $this->assertCurrencyConflict(fn () => (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-02'));
            $this->assertCurrencyConflict(fn () => (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-02'));
            $this->assertSame('USD', (new IncomeStatementQuery)->execute($this->owner, '2026-01-02', '2026-01-02')['currency']);
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_currency_guard_distinguishes_codes_that_differ_only_by_case(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $this->revenueJournal('2026-01-01', '1.00', $asset, $revenue);
        $draft = $this->revenueJournal('2026-01-02', '2.00', $asset, $revenue, false);
        DB::table('journal_entries')->where('id', $draft->id)->update([
            'currency' => strtolower(config('accounting.currency')),
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $this->assertCurrencyConflict(fn () => (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-02'));
        $this->assertCurrencyConflict(fn () => (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-02'));
    }

    public function test_large_decimal_aggregates_keep_every_cent(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $this->revenueJournal('2026-01-01', '9999999999999.99', $asset, $revenue);
        $this->revenueJournal('2026-01-02', '9999999999999.99', $asset, $revenue);
        $this->assertSame('19999999999999.98', (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-02')['totals']['net_profit_loss']);
        $this->assertSame('19999999999999.98', (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-02')['totals']['assets']);
    }

    public function test_profitable_closed_year_keeps_historical_income_and_moves_profit_into_equity(): void
    {
        $asset = $this->account('1000', 'asset');
        $retained = $this->account('3000', 'equity');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->revenueJournal('2026-01-01', '100.01', $asset, $revenue);
        $this->journal('2026-12-31', [$this->line($expense, '40.00', '0'), $this->line($asset, '0', '40.00')]);
        $income = new IncomeStatementQuery;
        $position = new StatementOfFinancialPositionQuery;
        $beforeIncome = $income->execute($this->owner, '2026-01-01', '2026-12-31');
        $this->assertSame('60.01', $position->execute($this->owner, '2026-12-31')['totals']['unclosed_cumulative_profit_loss']);

        $close = (new CloseFiscalYear)->execute($this->owner, '2026-01-01', '2026-12-31', config('accounting.currency'), $retained->id);
        $this->assertNotNull($close->journal_entry_id);
        $this->assertSame($beforeIncome, $income->execute($this->owner, '2026-01-01', '2026-12-31'));
        $after = $position->execute($this->owner, '2026-12-31');
        $this->assertSame('60.01', $after['totals']['assets']);
        $this->assertSame('60.01', $after['totals']['equity']);
        $this->assertSame('0.00', $after['totals']['unclosed_cumulative_profit_loss']);
        $this->assertSame('60.01', $after['totals']['liabilities_equity_and_unclosed_profit_loss']);
        $this->assertSame('0.00', $after['totals']['equation_difference']);
        $this->assertSame('60.01', $after['equity'][0]['amount']);

        $trial = (new TrialBalanceQuery)->execute($this->owner, '2026-12-31');
        $byCode = array_column($trial['accounts'], null, 'code');
        $this->assertSame('0.00', $byCode['4000']['signed_net']);
        $this->assertSame('0.00', $byCode['5000']['signed_net']);
        $this->assertSame('-60.01', $byCode['3000']['signed_net']);
    }

    public function test_loss_and_contra_balances_stay_in_closed_year_income_but_not_unclosed_position(): void
    {
        $asset = $this->account('1000', 'asset');
        $retained = $this->account('3000', 'equity');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->journal('2026-01-01', [$this->line($revenue, '20.00', '0'), $this->line($asset, '0', '20.00')]);
        $this->journal('2026-12-31', [$this->line($asset, '5.00', '0'), $this->line($expense, '0', '5.00')]);
        $income = new IncomeStatementQuery;
        $before = $income->execute($this->owner, '2026-01-01', '2026-12-31');
        $this->assertSame(['total_revenue' => '-20.00', 'total_expense' => '-5.00', 'net_profit_loss' => '-15.00'], $before['totals']);
        (new CloseFiscalYear)->execute($this->owner, '2026-01-01', '2026-12-31', config('accounting.currency'), $retained->id);
        $this->assertSame($before, $income->execute($this->owner, '2026-01-01', '2026-12-31'));
        $after = (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-12-31');
        $this->assertSame('-15.00', $after['totals']['assets']);
        $this->assertSame('-15.00', $after['totals']['equity']);
        $this->assertSame('0.00', $after['totals']['unclosed_cumulative_profit_loss']);
        $this->assertSame('0.00', $after['totals']['equation_difference']);
    }

    public function test_later_year_profit_remains_unclosed_and_ordinary_reversal_keeps_its_own_date(): void
    {
        $asset = $this->account('1000', 'asset');
        $retained = $this->account('3000', 'equity');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->revenueJournal('2026-12-31', '60.01', $asset, $revenue);
        (new CloseFiscalYear)->execute($this->owner, '2026-01-01', '2026-12-31', config('accounting.currency'), $retained->id);
        $laterRevenue = $this->revenueJournal('2027-01-01', '10.00', $asset, $revenue);
        $this->journal('2027-01-01', [$this->line($expense, '3.00', '0'), $this->line($asset, '0', '3.00')]);
        $position = (new StatementOfFinancialPositionQuery)->execute($this->owner, '2027-01-01');
        $this->assertSame('60.01', $position['totals']['equity']);
        $this->assertSame('7.00', $position['totals']['unclosed_cumulative_profit_loss']);
        $this->assertSame('67.01', $position['totals']['assets']);
        $this->assertSame('0.00', $position['totals']['equation_difference']);
        $this->assertSame('7.00', (new IncomeStatementQuery)->execute($this->owner, '2027-01-01', '2027-01-01')['totals']['net_profit_loss']);

        $this->travelTo(Carbon::parse('2027-01-02'));
        try {
            (new ReverseJournalEntry)->execute($this->owner, $laterRevenue->id);
        } finally {
            $this->travelBack();
        }
        $income = new IncomeStatementQuery;
        $this->assertSame('-10.00', $income->execute($this->owner, '2027-01-02', '2027-01-02')['totals']['net_profit_loss']);
        $this->assertSame('60.01', $income->execute($this->owner, '2026-01-01', '2026-12-31')['totals']['net_profit_loss']);
        $this->assertSame('0.00', (new StatementOfFinancialPositionQuery)->execute($this->owner, '2027-01-02')['totals']['equation_difference']);
    }

    public function test_zero_activity_close_does_not_change_reports_and_owner_scope_isolated(): void
    {
        $retained = $this->account('3000', 'equity');
        $otherRetained = $this->account('3000', 'equity', $this->other);
        $otherAsset = $this->account('1000', 'asset', $this->other);
        $otherRevenue = $this->account('4000', 'revenue', $this->other);
        $this->revenueJournal('2026-01-01', '99.00', $otherAsset, $otherRevenue, owner: $this->other);
        $before = (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-12-31');
        $close = (new CloseFiscalYear)->execute($this->owner, '2026-01-01', '2026-12-31', config('accounting.currency'), $retained->id);
        $this->assertNull($close->journal_entry_id);
        $this->assertSame($before, (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-12-31'));
        $this->assertSame('0.00', (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-12-31')['totals']['equation_difference']);
        $otherBefore = (new IncomeStatementQuery)->execute($this->other, '2026-01-01', '2026-12-31');
        (new CloseFiscalYear)->execute($this->other, '2026-01-01', '2026-12-31', config('accounting.currency'), $otherRetained->id);
        $this->assertSame($otherBefore, (new IncomeStatementQuery)->execute($this->other, '2026-01-01', '2026-12-31'));
        $this->assertSame($before, (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-12-31'));
    }

    public function test_closed_year_preserves_exact_maximum_and_mixed_currency_detection(): void
    {
        $asset = $this->account('1000', 'asset');
        $retained = $this->account('3000', 'equity');
        $revenue = $this->account('4000', 'revenue');
        $this->revenueJournal('2026-12-31', '9999999999999.99', $asset, $revenue);
        (new CloseFiscalYear)->execute($this->owner, '2026-01-01', '2026-12-31', config('accounting.currency'), $retained->id);
        $income = new IncomeStatementQuery;
        $position = new StatementOfFinancialPositionQuery;
        $this->assertSame('9999999999999.99', $income->execute($this->owner, '2026-01-01', '2026-12-31')['totals']['net_profit_loss']);
        $this->assertSame('9999999999999.99', $position->execute($this->owner, '2026-12-31')['totals']['equity']);
        $this->assertSame('0.00', $position->execute($this->owner, '2026-12-31')['totals']['unclosed_cumulative_profit_loss']);
        $configured = config('accounting.currency');
        try {
            config(['accounting.currency' => $configured === 'SAR' ? 'USD' : 'SAR']);
            $this->revenueJournal('2027-01-01', '0.01', $asset, $revenue);
            $this->assertCurrencyConflict(fn () => $income->execute($this->owner, '2026-01-01', '2027-01-01'));
            $this->assertCurrencyConflict(fn () => $position->execute($this->owner, '2027-01-01'));
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_corrupt_unbalanced_posted_ledger_is_rejected_without_mutation(): void
    {
        $asset = $this->account('1000', 'asset');
        $draft = $this->journal('2026-01-01', [$this->line($asset, '0.01', '0')], false);
        DB::table('journal_entries')->where('id', $draft->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $before = $draft->fresh()->getAttributes();

        foreach ([
            fn () => (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-01'),
            fn () => (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-01'),
        ] as $query) {
            try {
                $query();
                $this->fail('Expected an out-of-balance posted-ledger conflict.');
            } catch (AccountingConflict $exception) {
                $this->assertStringContainsString('out-of-balance', $exception->getMessage());
            }
        }
        $this->assertSame($before, $draft->fresh()->getAttributes());
    }

    public function test_offsetting_unbalanced_posted_journals_are_rejected(): void
    {
        $asset = $this->account('1000', 'asset');
        $liability = $this->account('2000', 'liability');
        $equity = $this->account('3000', 'equity');
        $this->journal('2026-01-01', [
            $this->line($asset, '2.00', '0'),
            $this->line($equity, '0', '2.00'),
        ]);
        $this->assertSame('2.00', (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-01')['totals']['assets']);

        $debitOnly = $this->journal('2026-01-02', [$this->line($asset, '1.00', '0')], false);
        $creditOnly = $this->journal('2026-01-02', [$this->line($liability, '0', '1.00')], false);
        DB::table('journal_entries')->whereIn('id', [$debitOnly->id, $creditOnly->id])
            ->update(['status' => 'posted', 'posted_at' => now()]);

        $ledgerTotals = DB::table('journal_lines')->where('user_id', $this->owner->id)
            ->selectRaw('SUM(debit) AS debits, SUM(credit) AS credits')->first();
        $this->assertSame('3.00', $ledgerTotals->debits);
        $this->assertSame($ledgerTotals->debits, $ledgerTotals->credits);
        try {
            (new TrialBalanceQuery)->execute($this->owner, '2026-01-02');
            $this->fail('Trial Balance must reject offsetting individually unbalanced journals.');
        } catch (AccountingConflict $exception) {
            $this->assertStringContainsString('out of balance', $exception->getMessage());
        }
        foreach ([
            fn () => (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-02'),
            fn () => (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-02'),
        ] as $query) {
            try {
                $query();
                $this->fail('Expected an individually unbalanced posted-journal conflict.');
            } catch (AccountingConflict $exception) {
                $this->assertStringContainsString('out-of-balance', $exception->getMessage());
            }
        }
    }

    public function test_date_validation_and_one_nonlocking_statement_per_report(): void
    {
        try {
            (new IncomeStatementQuery)->execute($this->owner, '2026-02-01', '2026-01-31');
            $this->fail('Expected date range validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('date_to', $exception->errors());
        }
        try {
            (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-02-30');
            $this->fail('Expected cutoff validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('as_of', $exception->errors());
        }

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            (new IncomeStatementQuery)->execute($this->owner, '2026-01-01', '2026-01-31');
            $this->assertCount(1, DB::getQueryLog());
            $this->assertStringNotContainsString('for update', strtolower(DB::getQueryLog()[0]['query']));
            $this->assertStringContainsString('not exists (select 1 from fiscal_year_closes', strtolower(DB::getQueryLog()[0]['query']));
            DB::flushQueryLog();
            (new StatementOfFinancialPositionQuery)->execute($this->owner, '2026-01-31');
            $this->assertCount(1, DB::getQueryLog());
            $this->assertStringNotContainsString('for update', strtolower(DB::getQueryLog()[0]['query']));
            $this->assertStringNotContainsString('fiscal_year_closes', strtolower(DB::getQueryLog()[0]['query']));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
