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

class LegacyPageRelationIntegrityTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private User $other;
    private Account $ownAccount;
    private Account $foreignAccount;
    private Category $ownCategory;
    private Category $foreignCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->ownAccount = $this->account($this->owner, 'Own account');
        $this->foreignAccount = $this->account($this->other, 'Private foreign account');
        $this->ownCategory = $this->category($this->owner, 'Own category');
        $this->foreignCategory = $this->category($this->other, 'Private foreign category');
    }

    public function test_transaction_page_rejects_a_foreign_account_relation_without_exposing_it(): void
    {
        $this->transaction($this->owner, $this->foreignAccount, $this->ownCategory);
        $this->assertTransactionIntegrityFailure();
    }

    public function test_transaction_page_rejects_a_foreign_category_relation_without_exposing_it(): void
    {
        $this->transaction($this->owner, $this->ownAccount, $this->foreignCategory);
        $this->assertTransactionIntegrityFailure();
    }

    public function test_transaction_page_rejects_both_foreign_relations_without_exposing_either(): void
    {
        $this->transaction($this->owner, $this->foreignAccount, $this->foreignCategory);
        $this->assertTransactionIntegrityFailure();
    }

    public function test_budget_page_rejects_a_foreign_category_relation_without_exposing_it(): void
    {
        $this->budget($this->owner, $this->foreignCategory);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/budgets')->assertStatus(409)
            ->assertSee('تعذر عرض البيانات المالية')
            ->assertDontSee('Private foreign category')
            ->assertDontSee('Private foreign account');
        $this->assertOwnerScopedRelationQuery('categories');
        DB::disableQueryLog();
    }

    public function test_owned_rows_linked_to_unassigned_legacy_relations_fail_safely(): void
    {
        $unassignedCategory = Category::create(['name' => 'Unassigned private category', 'type' => 'expense']);
        $this->transaction($this->owner, $this->ownAccount, $unassignedCategory);
        $this->budget($this->owner, $unassignedCategory);

        $this->actingAs($this->owner)->get('/transactions')->assertStatus(409)
            ->assertDontSee('Unassigned private category');
        $this->get('/budgets')->assertStatus(409)
            ->assertDontSee('Unassigned private category');
    }

    public function test_valid_pages_render_owned_relations_and_hide_foreign_and_unassigned_rows(): void
    {
        $ownTransaction = $this->transaction($this->owner, $this->ownAccount, $this->ownCategory);
        $ownBudget = $this->budget($this->owner, $this->ownCategory);
        $this->transaction($this->other, $this->foreignAccount, $this->foreignCategory);
        $this->budget($this->other, $this->foreignCategory);
        $unassignedAccount = Account::create(['name' => 'Unassigned account', 'type' => 'cash', 'balance' => '0.00']);
        $unassignedCategory = Category::create(['name' => 'Unassigned category', 'type' => 'expense']);
        Transaction::create(['account_id' => $unassignedAccount->id, 'category_id' => $unassignedCategory->id,
            'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-09']);
        Budget::create(['category_id' => $unassignedCategory->id, 'amount' => '1.00', 'month' => 11, 'year' => 2026]);

        $this->actingAs($this->owner)->get('/transactions')->assertOk()
            ->assertViewHas('transactions', fn ($rows) => $rows->modelKeys() === [$ownTransaction->id]
                && $rows->first()->relationLoaded('account') && $rows->first()->relationLoaded('category')
                && $rows->first()->account->is($this->ownAccount) && $rows->first()->category->is($this->ownCategory))
            ->assertSee('Own account')->assertSee('Own category')
            ->assertDontSee('Private foreign account')->assertDontSee('Private foreign category')
            ->assertDontSee('Unassigned account')->assertDontSee('Unassigned category');
        $this->get('/budgets')->assertOk()
            ->assertViewHas('budgets', fn ($rows) => $rows->modelKeys() === [$ownBudget->id]
                && $rows->first()->relationLoaded('category') && $rows->first()->category->is($this->ownCategory))
            ->assertSee('Own category')->assertDontSee('Private foreign category')
            ->assertDontSee('Unassigned category');
    }

    public function test_normal_pages_eager_load_relations_with_bounded_query_counts(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->transaction($this->owner, $this->ownAccount, $this->ownCategory);
            $this->budget($this->owner, $this->ownCategory, $index + 1);
        }
        $this->actingAs($this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/transactions')->assertOk();
        $transactionQueries = collect(DB::getQueryLog())->pluck('query');
        $this->assertSame(1, $transactionQueries->filter(fn ($sql) => str_contains($sql, 'from `transactions`'))->count());
        $this->assertSame(2, $transactionQueries->filter(fn ($sql) => str_contains($sql, 'from `accounts`'))->count());
        $this->assertSame(2, $transactionQueries->filter(fn ($sql) => str_contains($sql, 'from `categories`'))->count());

        DB::flushQueryLog();
        $this->get('/budgets')->assertOk();
        $budgetQueries = collect(DB::getQueryLog())->pluck('query');
        $this->assertSame(1, $budgetQueries->filter(fn ($sql) => str_contains($sql, 'from `budgets`'))->count());
        $this->assertSame(2, $budgetQueries->filter(fn ($sql) => str_contains($sql, 'from `categories`'))->count());
        DB::disableQueryLog();
    }

    private function assertTransactionIntegrityFailure(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/transactions')->assertStatus(409)
            ->assertSee('تعذر عرض البيانات المالية')
            ->assertDontSee('Private foreign account')
            ->assertDontSee('Private foreign category');
        $this->assertOwnerScopedRelationQuery('accounts');
        $this->assertOwnerScopedRelationQuery('categories');
        DB::disableQueryLog();
    }

    private function assertOwnerScopedRelationQuery(string $table): void
    {
        $queries = collect(DB::getQueryLog())->filter(fn ($entry) => str_contains($entry['query'], 'from `'.$table.'`'));
        $this->assertCount(1, $queries);
        $query = $queries->first();
        $this->assertStringContainsString('`user_id`', $query['query']);
        $this->assertContains($this->owner->id, $query['bindings']);
    }

    private function account(User $owner, string $name): Account
    {
        return $owner->accounts()->create(['name' => $name, 'type' => 'bank', 'balance' => '0.00', 'currency' => 'SAR']);
    }

    private function category(User $owner, string $name): Category
    {
        return $owner->categories()->create(['name' => $name, 'type' => 'expense']);
    }

    private function transaction(User $owner, Account $account, Category $category): Transaction
    {
        return $owner->transactions()->create(['account_id' => $account->id, 'category_id' => $category->id,
            'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-09']);
    }

    private function budget(User $owner, Category $category, int $month = 10): Budget
    {
        return $owner->budgets()->create(['category_id' => $category->id, 'amount' => '1.00', 'month' => $month, 'year' => 2026]);
    }
}
