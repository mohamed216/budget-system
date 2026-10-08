<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class DashboardMoneyPresentationTest extends TestCase
{
    use RefreshFinancialDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_empty_and_single_currency_dashboard_keep_the_view_contract(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->get('/')->assertOk()
            ->assertViewHas('totalBalance', 0)->assertViewHas('totalIncome', 0)
            ->assertViewHas('totalExpense', 0)->assertSee('0.00');

        $account = $this->account($owner, 'USD', '10.03');
        $this->transaction($owner, $account, $this->category($owner, 'income'), 'income', '10.03');
        $this->transaction($owner, $account, $this->category($owner, 'expense'), 'expense', '4.02');
        $this->get('/')->assertOk()->assertViewHas('totalBalance', '10.03')
            ->assertViewHas('totalIncome', '10.03')->assertSee('USD')->assertSee('6.01');
    }

    public function test_mixed_owner_currencies_have_an_explicit_safe_state_without_combined_totals(): void
    {
        $owner = User::factory()->create();
        $sar = $this->account($owner, 'SAR', '10.00');
        $usd = $this->account($owner, 'USD', '20.00');
        $category = $this->category($owner, 'income');
        $this->transaction($owner, $sar, $category, 'income', '10.00');
        $this->transaction($owner, $usd, $category, 'income', '20.00');

        $this->actingAs($owner)->get('/')->assertStatus(409)
            ->assertSee('عملات')->assertDontSee('30.00');
    }

    public function test_another_users_currency_does_not_affect_the_dashboard(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->account($owner, 'SAR', '10.00');
        $this->account($other, 'USD', '20.00');

        $this->actingAs($owner)->get('/')->assertOk()->assertViewHas('totalBalance', '10.00')
            ->assertDontSee('USD');
    }

    public function test_case_distinct_currencies_are_not_combined(): void
    {
        $owner = User::factory()->create();
        $this->account($owner, 'USD', '10.00');
        $this->account($owner, 'usd', '20.00');

        $this->actingAs($owner)->get('/')->assertStatus(409)->assertSee('عملات')
            ->assertDontSee('30.00');
    }

    public function test_corrupt_cross_owner_transaction_link_fails_without_exposing_foreign_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownAccount = $this->account($owner, 'SAR', '10.00');
        $foreignAccount = $this->account($other, 'USD', '20.00');
        $foreignAccount->update(['name' => 'Private foreign account']);
        $category = $this->category($owner, 'income');
        $this->transaction($owner, $ownAccount, $category, 'income', '10.00');
        $this->transaction($owner, $foreignAccount, $category, 'income', '20.00');

        $this->actingAs($owner)->get('/')->assertStatus(409)
            ->assertViewHas('corruptOwnerLink', true)
            ->assertSee('بيانات الحسابات')
            ->assertDontSee('Private foreign account')
            ->assertDontSee('30.00');
    }

    public function test_corrupt_cross_owner_category_link_fails_without_exposing_foreign_category(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = $this->account($owner, 'SAR', '10.00');
        $ownCategory = $this->category($owner, 'income');
        $foreignCategory = $this->category($other, 'income');
        $foreignCategory->update(['name' => 'Private foreign category']);
        $this->transaction($owner, $account, $ownCategory, 'income', '10.00');
        $this->transaction($owner, $account, $foreignCategory, 'income', '20.00');

        $this->actingAs($owner)->get('/')->assertStatus(409)
            ->assertViewHas('corruptOwnerLink', true)
            ->assertSee('بيانات الحسابات')
            ->assertDontSee('Private foreign category')
            ->assertDontSee('30.00')
            ->assertDontSee('آخر المعاملات');
    }

    public function test_valid_owner_category_link_keeps_recent_transaction_presentation(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, 'SAR', '10.00');
        $category = $this->category($owner, 'income');
        $category->update(['name' => 'Owned income category']);
        $this->transaction($owner, $account, $category, 'income', '10.00');

        $this->actingAs($owner)->get('/')->assertOk()
            ->assertViewHas('totalIncome', '10.00')
            ->assertSee('Owned income category')
            ->assertSee('آخر المعاملات');
    }

    public function test_dashboard_uses_one_snapshot_when_another_connection_adds_a_currency_mid_read(): void
    {
        $owner = User::factory()->create();
        $sar = $this->account($owner, 'SAR', '10.00');
        $category = $this->category($owner, 'income');
        $this->transaction($owner, $sar, $category, 'income', '10.00');

        $ownerId = $owner->id;
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.dashboard_race_probe' => config('database.connections.mysql_testing')]);
        $probe = DB::connection('dashboard_race_probe');
        DB::commit(); // Publish fixtures for the second connection.

        $inserted = false;
        DB::listen(function ($event) use ($probe, $ownerId, $category, &$inserted): void {
            if ($inserted || $event->connectionName !== 'mysql_testing'
                || ! str_contains($event->sql, 'COUNT(DISTINCT CAST(currency AS BINARY))')) {
                return;
            }
            $inserted = true;
            $probe->transaction(function () use ($probe, $ownerId, $category): void {
                $accountId = $probe->table('accounts')->insertGetId([
                    'user_id' => $ownerId, 'name' => 'Concurrent USD account', 'type' => 'bank',
                    'balance' => '20.00', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $probe->table('transactions')->insert([
                    'user_id' => $ownerId, 'account_id' => $accountId, 'category_id' => $category->id,
                    'type' => 'income', 'amount' => '20.00', 'date' => '2026-10-09',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        });

        try {
            $response = $this->actingAs($owner)->get('/');
            $this->assertTrue($inserted, 'The second connection must commit between dashboard reads.');
            $this->assertContains($response->getStatusCode(), [200, 409]);
            $response->assertDontSee('30.00');
            if ($response->getStatusCode() === 200) {
                $response->assertViewHas('totalIncome', '10.00')->assertDontSee('Concurrent USD account');
            }
        } finally {
            DB::setDefaultConnection($originalConnection);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('transactions')->where('user_id', $ownerId)->delete();
            DB::table('accounts')->where('user_id', $ownerId)->delete();
            DB::table('categories')->where('user_id', $ownerId)->delete();
            DB::table('users')->where('id', $ownerId)->delete();
            DB::beginTransaction();
            DB::disconnect('dashboard_race_probe');
        }
    }

    public function test_single_currency_loss_uses_exact_signed_subtraction(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, 'SAR', '0.00');
        $this->transaction($owner, $account, $this->category($owner, 'income'), 'income', '0.01');
        $this->transaction($owner, $account, $this->category($owner, 'expense'), 'expense', '10.00');

        $this->actingAs($owner)->get('/')->assertOk()->assertViewHas('netIncome', '-9.99')
            ->assertSee('-9.99');
    }

    public function test_large_dashboard_aggregates_and_cent_difference_render_exactly(): void
    {
        $owner = User::factory()->create();
        $incomeCategory = $this->category($owner, 'income');
        $expenseCategory = $this->category($owner, 'expense');
        $accounts = [];
        for ($index = 0; $index < 10; $index++) {
            $accounts[] = $this->account($owner, 'SAR', '9999999999999.99');
        }
        $this->account($owner, 'SAR', '0.09');
        foreach ($accounts as $account) {
            $this->transaction($owner, $account, $incomeCategory, 'income', '9999999999999.99');
        }
        $this->transaction($owner, $accounts[0], $expenseCategory, 'expense', '0.01');

        $this->actingAs($owner)->get('/')->assertOk()
            ->assertSee('99,999,999,999,999.99')
            ->assertSee('99,999,999,999,999.90')
            ->assertSee('99,999,999,999,999.89')
            ->assertDontSee('100,000,000,000,000.00');
    }

    public function test_account_budget_and_transaction_pages_render_exact_large_and_small_amounts(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, 'SAR', '9999999999999.99');
        $this->account($owner, 'SAR', '0.00');
        $category = $this->category($owner, 'expense');
        $owner->budgets()->create(['category_id' => $category->id, 'amount' => '9999999999999.99', 'month' => 10, 'year' => 2026]);
        $owner->budgets()->create(['category_id' => $category->id, 'amount' => '0.01', 'month' => 11, 'year' => 2026]);
        $this->transaction($owner, $account, $category, 'expense', '9999999999999.99');
        $this->actingAs($owner);

        foreach (['/accounts', '/budgets', '/transactions'] as $path) {
            $this->get($path)->assertOk()->assertSee('9,999,999,999,999.99');
        }
        $this->get('/accounts')->assertSee('0.00');
        $this->get('/budgets')->assertSee('0.01');
        $this->transaction($owner, $account, $this->category($owner, 'income'), 'income', '0.01');
        $this->get('/transactions')->assertSee('0.01');
    }

    private function account(User $owner, string $currency, string $balance): Account
    {
        return $owner->accounts()->create(['name' => 'Account', 'type' => 'bank', 'balance' => $balance, 'currency' => $currency]);
    }

    private function category(User $owner, string $type): Category
    {
        return $owner->categories()->create(['name' => 'Category', 'type' => $type]);
    }

    private function transaction(User $owner, Account $account, Category $category, string $type, string $amount): Transaction
    {
        return $owner->transactions()->create([
            'account_id' => $account->id, 'category_id' => $category->id,
            'type' => $type, 'amount' => $amount, 'date' => '2026-10-09',
        ]);
    }
}
