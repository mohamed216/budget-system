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

class CashFlowStatementHttpTest extends TestCase
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
        return '/accounting/cash-flow?start_date=2026-01-01&end_date=2026-01-31';
    }

    public function test_json_report_is_owner_scoped_and_all_money_is_exact_strings(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $draft = $this->draft($owner, $cash, $revenue, '12.34', [[
            'debit_line_index' => 0, 'credit_line_index' => 1, 'amount' => '12.34', 'category' => 'operating',
        ]]);
        (new PostJournalEntry)->execute($owner, $draft->id);

        $other = User::factory()->create();
        $otherCash = $this->account($other, '1000', 'asset', 'cash');
        $otherRevenue = $this->account($other, '4000', 'revenue', 'non_cash');
        $otherDraft = $this->draft($other, $otherCash, $otherRevenue, '99.99', [[
            'debit_line_index' => 0, 'credit_line_index' => 1, 'amount' => '99.99', 'category' => 'operating',
        ]]);
        (new PostJournalEntry)->execute($other, $otherDraft->id);

        $response = $this->actingAs($owner)->getJson($this->url().'&user_id='.$other->id)->assertOk();
        $this->assertSame([
            'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
            'currency' => config('accounting.currency'), 'has_designated_cash_accounts' => true,
            'beginning_cash' => '0.00', 'opening_balance_adjustments' => '0.00',
            'operating_activities' => '12.34', 'investing_activities' => '0.00',
            'financing_activities' => '0.00', 'net_cash_flow' => '12.34',
            'net_change_in_cash' => '12.34', 'ending_cash' => '12.34',
        ], $response->json('data'));
        foreach (['beginning_cash', 'opening_balance_adjustments', 'operating_activities',
            'investing_activities', 'financing_activities', 'net_cash_flow', 'net_change_in_cash',
            'ending_cash'] as $key) {
            $this->assertIsString($response->json('data.'.$key));
        }
    }

    public function test_empty_owner_has_zero_strings_and_guests_are_denied(): void
    {
        $this->getJson($this->url())->assertUnauthorized();
        $owner = User::factory()->create();
        $response = $this->actingAs($owner)->getJson($this->url())->assertOk();
        $this->assertFalse($response->json('data.has_designated_cash_accounts'));
        foreach (['beginning_cash', 'opening_balance_adjustments', 'operating_activities',
            'investing_activities', 'financing_activities', 'net_cash_flow', 'net_change_in_cash',
            'ending_cash'] as $key) {
            $this->assertSame('0.00', $response->json('data.'.$key));
        }
    }

    public function test_invalid_dates_use_json_validation_errors(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/accounting/cash-flow')->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson('/accounting/cash-flow?start_date=2026-02-01&end_date=2026-01-01')
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_unreviewed_and_incomplete_classification_have_safe_conflicts(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', null);
        $draft = $this->draft($owner, $cash, $revenue, '1.00');
        $this->historicalPost($draft);
        $this->actingAs($owner)->getJson($this->url())->assertStatus(409)
            ->assertExactJson(['message' => 'راجع تصنيف جميع الحسابات المستخدمة في القيود المرحلة قبل عرض قائمة التدفقات النقدية.']);

        (new ReviewCashAccountRole)->execute($owner, $revenue->id, 'non_cash');
        $this->getJson($this->url())->assertStatus(409)
            ->assertExactJson(['message' => 'أكمل توزيع التدفقات النقدية للقيود المرحلة في الفترة المختارة قبل عرض القائمة.']);
    }

    public function test_corrupt_classification_and_mixed_currency_have_safe_conflicts(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $revenue = $this->account($owner, '4000', 'revenue', 'non_cash');
        $draft = $this->draft($owner, $cash, $revenue, '1.00');
        $this->historicalPost($draft);
        DB::table('journal_line_allocations')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $draft->id,
            'debit_line_id' => $draft->lines[0]->id, 'credit_line_id' => $draft->lines[1]->id,
            'amount' => '1.00', 'category' => null,
        ]);
        DB::table('cash_flow_journal_completions')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $draft->id, 'completed_at' => now(),
        ]);
        $this->actingAs($owner)->getJson($this->url())->assertStatus(409)
            ->assertExactJson(['message' => 'تعذر عرض قائمة التدفقات النقدية لوجود بيانات مرحلة غير متسقة. راجع القيود والتوزيعات.']);

        $mixedOwner = User::factory()->create();
        $expense = $this->account($mixedOwner, '5000', 'expense', 'non_cash');
        $payable = $this->account($mixedOwner, '2000', 'liability', 'non_cash');
        $first = $this->draft($mixedOwner, $expense, $payable, '1.00');
        $second = $this->draft($mixedOwner, $expense, $payable, '2.00');
        $this->historicalPost($first, 'SAR');
        $this->historicalPost($second, 'USD');
        $this->actingAs($mixedOwner)->getJson($this->url())->assertStatus(409)
            ->assertExactJson(['message' => 'تعذر عرض قائمة التدفقات النقدية بسبب اختلاف عملات القيود المرحلة.']);
    }
}
