<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FinancialStatementHttpTest extends TestCase
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

    private function income(string $from, string $to): string
    {
        return '/accounting/income-statement?'.http_build_query(['date_from' => $from, 'date_to' => $to]);
    }

    private function position(string $asOf): string
    {
        return '/accounting/balance-sheet?'.http_build_query(['as_of' => $asOf]);
    }

    public function test_guests_cannot_read_either_statement(): void
    {
        $this->getJson($this->income('2026-01-01', '2026-01-31'))->assertUnauthorized();
        $this->getJson($this->position('2026-01-31'))->assertUnauthorized();
    }

    public function test_income_is_owner_scoped_posted_only_inclusive_and_exact(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->journal('2025-12-31', [$this->line($asset, '99.00', '0'), $this->line($revenue, '0', '99.00')]);
        $this->journal('2026-01-01', [$this->line($asset, '10.03', '0'), $this->line($revenue, '0', '10.03')]);
        $this->journal('2026-01-31', [$this->line($expense, '4.02', '0'), $this->line($asset, '0', '4.02')]);
        $this->journal('2026-02-01', [$this->line($asset, '99.00', '0'), $this->line($revenue, '0', '99.00')]);
        $this->journal('2026-01-15', [$this->line($asset, '99.00', '0'), $this->line($revenue, '0', '99.00')], false);
        $foreignAsset = $this->account('1000', 'asset', $this->other);
        $foreignRevenue = $this->account('4000', 'revenue', $this->other);
        $this->journal('2026-01-15', [$this->line($foreignAsset, '99.00', '0'), $this->line($foreignRevenue, '0', '99.00')], owner: $this->other);

        $this->actingAs($this->owner)->getJson($this->income('2026-01-01', '2026-01-31').'&user_id='.$this->other->id)
            ->assertOk()->assertJsonPath('data.date_from', '2026-01-01')
            ->assertJsonPath('data.date_to', '2026-01-31')
            ->assertJsonPath('data.revenue_accounts.0.amount', '10.03')
            ->assertJsonPath('data.expense_accounts.0.amount', '4.02')
            ->assertJsonPath('data.total_revenue', '10.03')
            ->assertJsonPath('data.total_expense', '4.02')
            ->assertJsonPath('data.net_profit_loss', '6.01')
            ->assertJsonCount(1, 'data.revenue_accounts')->assertJsonCount(1, 'data.expense_accounts');
    }

    public function test_balance_sheet_is_owner_scoped_exact_and_reconciles_with_net_loss(): void
    {
        $asset = $this->account('1000', 'asset');
        $liability = $this->account('2000', 'liability');
        $equity = $this->account('3000', 'equity');
        $expense = $this->account('5000', 'expense');
        $this->journal('2026-01-01', [$this->line($asset, '10.00', '0'), $this->line($liability, '0', '4.00'), $this->line($equity, '0', '6.00')]);
        $this->journal('2026-01-31', [$this->line($expense, '0.01', '0'), $this->line($asset, '0', '0.01')]);
        $foreignAsset = $this->account('1000', 'asset', $this->other);
        $foreignEquity = $this->account('3000', 'equity', $this->other);
        $this->journal('2026-01-31', [$this->line($foreignAsset, '99.00', '0'), $this->line($foreignEquity, '0', '99.00')], owner: $this->other);

        $this->actingAs($this->owner)->getJson($this->position('2026-01-31').'&user_id='.$this->other->id)
            ->assertOk()->assertJsonPath('data.as_of', '2026-01-31')
            ->assertJsonPath('data.assets.0.amount', '9.99')
            ->assertJsonPath('data.liabilities.0.amount', '4.00')
            ->assertJsonPath('data.equity.0.amount', '6.00')
            ->assertJsonPath('data.total_assets', '9.99')
            ->assertJsonPath('data.total_liabilities', '4.00')
            ->assertJsonPath('data.total_equity', '6.00')
            ->assertJsonPath('data.unclosed_cumulative_profit_loss', '-0.01')
            ->assertJsonPath('data.liabilities_equity_and_unclosed_profit_loss', '9.99')
            ->assertJsonPath('data.equation_difference', '0.00');
        $this->getJson($this->income('2026-01-01', '2026-01-31'))->assertOk()
            ->assertJsonPath('data.net_profit_loss', '-0.01');
    }

    public function test_reversal_is_reported_on_its_own_date(): void
    {
        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        $original = $this->journal('2026-01-05', [$this->line($asset, '5.00', '0'), $this->line($revenue, '0', '5.00')]);
        $this->travelTo(Carbon::parse('2026-02-01 12:00:00'));
        try {
            (new ReverseJournalEntry)->execute($this->owner, $original->id);
        } finally {
            $this->travelBack();
        }

        $this->actingAs($this->owner)->getJson($this->income('2026-01-01', '2026-01-31'))->assertOk()
            ->assertJsonPath('data.net_profit_loss', '5.00');
        $this->getJson($this->income('2026-02-01', '2026-02-01'))->assertOk()
            ->assertJsonPath('data.net_profit_loss', '-5.00');
        $this->getJson($this->position('2026-01-31'))->assertOk()->assertJsonPath('data.total_assets', '5.00');
        $this->getJson($this->position('2026-02-01'))->assertOk()->assertJsonPath('data.total_assets', '0.00');
    }

    public function test_required_strict_dates_and_order_use_validation_responses(): void
    {
        $this->actingAs($this->owner);
        $this->getJson('/accounting/income-statement')->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'date_to']);
        $this->getJson($this->income('2026-02-30', '2026-03-01'))->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson($this->income('2026-02-01', '2026-01-31'))->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/accounting/balance-sheet')->assertUnprocessable()->assertJsonValidationErrors('as_of');
        $this->getJson($this->position('2026-02-30'))->assertUnprocessable()->assertJsonValidationErrors('as_of');
    }

    public function test_report_get_dates_keep_existing_trimmed_query_input_contract(): void
    {
        $this->actingAs($this->owner);
        $this->getJson($this->income(' 2026-01-01 ', '2026-01-31 '))->assertOk()
            ->assertJsonPath('data.date_from', '2026-01-01')
            ->assertJsonPath('data.date_to', '2026-01-31');
        $this->getJson($this->position(' 2026-01-31 '))->assertOk()
            ->assertJsonPath('data.as_of', '2026-01-31');
        $this->getJson($this->income('2026-01-01', '2026-1-31'))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson($this->position('2026-1-31'))
            ->assertUnprocessable()->assertJsonValidationErrors('as_of');
    }

    public function test_empty_ledger_uses_configured_currency_and_historical_postings_use_actual_currency(): void
    {
        $configured = config('accounting.currency');
        $this->actingAs($this->owner)->getJson($this->income('2026-01-01', '2026-01-31'))->assertOk()
            ->assertJsonPath('data.currency', $configured)->assertJsonPath('data.net_profit_loss', '0.00');
        $this->getJson($this->position('2026-01-31'))->assertOk()
            ->assertJsonPath('data.currency', $configured)->assertJsonPath('data.equation_difference', '0.00');

        $asset = $this->account('1000', 'asset');
        $revenue = $this->account('4000', 'revenue');
        try {
            config(['accounting.currency' => 'SAR']);
            $this->journal('2026-01-01', [$this->line($asset, '1.00', '0'), $this->line($revenue, '0', '1.00')]);
            config(['accounting.currency' => 'USD']);
            $this->getJson($this->income('2026-01-01', '2026-01-01'))->assertOk()->assertJsonPath('data.currency', 'SAR');
            $this->getJson($this->position('2026-01-01'))->assertOk()->assertJsonPath('data.currency', 'SAR');
            $this->journal('2026-01-02', [$this->line($asset, '2.00', '0'), $this->line($revenue, '0', '2.00')]);
            $this->getJson($this->income('2026-01-01', '2026-01-02'))->assertConflict()
                ->assertExactJson(['message' => 'Financial report cannot combine journals with different currencies.']);
            $this->getJson($this->position('2026-01-02'))->assertConflict()
                ->assertExactJson(['message' => 'Financial report cannot combine journals with different currencies.']);
        } finally {
            config(['accounting.currency' => $configured]);
        }
    }

    public function test_corrupt_posted_journal_maps_to_sanitized_conflict(): void
    {
        $asset = $this->account('1000', 'asset');
        $draft = $this->journal('2026-01-01', [$this->line($asset, '0.01', '0')], false);
        DB::table('journal_entries')->where('id', $draft->id)->update(['status' => 'posted', 'posted_at' => now()]);

        $this->actingAs($this->owner)->getJson($this->income('2026-01-01', '2026-01-01'))->assertConflict()
            ->assertExactJson(['message' => 'Financial statement cannot be generated from an out-of-balance posted ledger.']);
        $this->getJson($this->position('2026-01-01'))->assertConflict()
            ->assertExactJson(['message' => 'Financial statement cannot be generated from an out-of-balance posted ledger.']);
    }
}
