<?php

namespace Tests\Feature;

use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReviewCashAccountRole;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class CashFlowStatementPageTest extends TestCase
{
    use RefreshFinancialDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function account(User $owner, string $code, string $type, ?string $role): ChartAccount
    {
        $account = $owner->chartAccounts()->create(['code' => $code, 'name' => $code, 'type' => $type]);
        if ($role !== null) {
            (new ReviewCashAccountRole)->execute($owner, $account->id, $role);
        }

        return $account;
    }

    private function draft(User $owner, ChartAccount $debit, ChartAccount $credit, string $amount, array $allocations = []): JournalEntry
    {
        return (new SaveJournalDraft)->execute($owner, '2026-01-15', config('accounting.currency'), [
            ['chart_account_id' => $debit->id, 'debit' => $amount, 'credit' => '0'],
            ['chart_account_id' => $credit->id, 'debit' => '0', 'credit' => $amount],
        ], allocations: $allocations);
    }

    private function historicalPost(JournalEntry $draft, ?string $currency = null): void
    {
        DB::table('journal_entries')->where('id', $draft->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'currency' => $currency ?? $draft->currency,
        ]);
    }

    private function url(): string
    {
        return route('accounting-pages.cash-flow', ['start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    }

    public function test_page_renders_exact_report_and_matches_json_values(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $draft = $this->draft($owner, $cash, $revenue, '12.34', [[
            'debit_line_index' => 0, 'credit_line_index' => 1, 'amount' => '12.34', 'category' => 'operating',
        ]]);
        (new PostJournalEntry)->execute($owner, $draft->id);

        $json = $this->actingAs($owner)->getJson('/accounting/cash-flow?start_date=2026-01-01&end_date=2026-01-31')
            ->assertOk()->json('data');
        $page = $this->get($this->url())->assertOk()->assertViewIs('accounting.cash-flow')
            ->assertViewHas('report', $json)
            ->assertSee('قائمة التدفقات النقدية')->assertSee('12.34')
            ->assertSee('صافي التدفق النقدي')->assertSee('الرصيد النقدي الختامي')
            ->assertSee('value="2026-01-01"', false)->assertSee('value="2026-01-31"', false);
        foreach (['beginning_cash', 'opening_balance_adjustments', 'operating_activities',
            'investing_activities', 'financing_activities', 'net_cash_flow', 'net_change_in_cash',
            'ending_cash'] as $field) {
            $page->assertSee($json[$field]);
        }
        $page->assertSee(route('accounting-pages.cash-flow'), false);
    }

    public function test_empty_page_explains_no_designated_cash_accounts_and_guests_redirect(): void
    {
        $this->get(route('accounting-pages.cash-flow'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('accounting-pages.cash-flow'))
            ->assertOk()->assertSee('لم تُحدَّد حسابات نقدية أو ما يعادلها')
            ->assertSee('0.00')->assertSee(config('accounting.currency'));
    }

    public function test_invalid_dates_show_errors_and_preserve_submitted_values(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->from(route('accounting-pages.cash-flow'))
            ->get(route('accounting-pages.cash-flow', ['start_date' => '2026-02-01', 'end_date' => '2026-01-01']))
            ->assertRedirect(route('accounting-pages.cash-flow'))->assertSessionHasErrors('end_date');
        $error = session('errors')->first('end_date');
        $this->get(route('accounting-pages.cash-flow'))->assertOk()
            ->assertSee('value="2026-02-01"', false)->assertSee('value="2026-01-01"', false)
            ->assertSee($error);
    }

    public function test_page_shows_safe_unreviewed_incomplete_corrupt_and_currency_messages(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', null);
        $draft = $this->draft($owner, $cash, $revenue, '1.00');
        $this->historicalPost($draft);
        $this->actingAs($owner)->get($this->url())->assertStatus(409)
            ->assertSee('راجع تصنيف جميع الحسابات')->assertDontSee('SQLSTATE');

        (new ReviewCashAccountRole)->execute($owner, $revenue->id, 'non_cash');
        $this->get($this->url())->assertStatus(409)
            ->assertSee('أكمل توزيع التدفقات النقدية')->assertDontSee('SQLSTATE');

        DB::table('journal_line_allocations')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $draft->id,
            'debit_line_id' => $draft->lines[0]->id, 'credit_line_id' => $draft->lines[1]->id,
            'amount' => '1.00', 'category' => null,
        ]);
        DB::table('cash_flow_journal_completions')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $draft->id, 'completed_at' => now(),
        ]);
        $this->get($this->url())->assertStatus(409)
            ->assertSee('بيانات مرحلة غير متسقة')->assertDontSee('SQLSTATE')
            ->assertDontSee('journal_line_allocations');

        $mixedOwner = User::factory()->create();
        $expense = $this->account($mixedOwner, '5000', 'expense', 'non_cash');
        $payable = $this->account($mixedOwner, '2000', 'liability', 'non_cash');
        $this->historicalPost($this->draft($mixedOwner, $expense, $payable, '1.00'), 'SAR');
        $this->historicalPost($this->draft($mixedOwner, $expense, $payable, '2.00'), 'USD');
        $this->actingAs($mixedOwner)->get($this->url())->assertStatus(409)
            ->assertSee('اختلاف عملات القيود المرحلة')->assertDontSee('SQLSTATE');
    }
}
