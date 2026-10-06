<?php

namespace Tests\Feature;

use App\Actions\PostTransaction;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FinancialFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $user;

    private Account $account;

    private Category $income;

    private Category $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->account = $this->user->accounts()->create(['name' => 'Own account', 'type' => 'bank', 'balance' => '100.00', 'currency' => 'SAR']);
        $this->income = $this->user->categories()->create(['name' => 'Own income', 'type' => 'income']);
        $this->expense = $this->user->categories()->create(['name' => 'Own expense', 'type' => 'expense']);
    }

    private function transactionData(array $overrides = []): array
    {
        return array_replace([
            'account_id' => $this->account->id,
            'category_id' => $this->income->id,
            'amount' => '10.25',
            'type' => 'income',
            'date' => '2026-10-05',
            'description' => 'Own transaction',
        ], $overrides);
    }

    private function budgetData(array $overrides = []): array
    {
        return array_replace(['category_id' => $this->expense->id, 'amount' => '50.25', 'month' => 10, 'year' => 2026], $overrides);
    }

    public function test_unauthenticated_users_cannot_access_financial_pages_or_writes(): void
    {
        foreach (['/', '/accounts', '/categories', '/transactions', '/budgets'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach (['accounts', 'categories', 'transactions', 'budgets'] as $resource) {
            $this->post('/'.$resource, [])->assertRedirect('/login');
        }
        $this->delete('/accounts/'.$this->account->id)->assertRedirect('/login');
        $this->delete('/categories/'.$this->income->id)->assertRedirect('/login');
        $this->delete('/budgets/1')->assertRedirect('/login');
        $this->getJson('/accounts')->assertUnauthorized();
    }

    public function test_authenticated_users_can_access_their_own_financial_data(): void
    {
        $transaction = app(PostTransaction::class)->handle($this->user, $this->transactionData());
        $budget = $this->user->budgets()->create($this->budgetData());
        $this->actingAs($this->user);

        $this->get('/')->assertOk()->assertViewHas('totalBalance', '110.25');
        $this->get('/accounts')->assertOk()->assertViewHas('accounts', fn ($rows) => $rows->modelKeys() === [$this->account->id]);
        $this->get('/categories')->assertOk()->assertViewHas('categories', fn ($rows) => $rows->count() === 2);
        $this->get('/transactions')->assertOk()->assertViewHas('transactions', fn ($rows) => $rows->modelKeys() === [$transaction->id]);
        $this->get('/budgets')->assertOk()->assertViewHas('budgets', fn ($rows) => $rows->modelKeys() === [$budget->id]);
    }

    public function test_user_cannot_access_another_users_account(): void
    {
        $other = User::factory()->create();
        $account = $other->accounts()->create(['name' => 'Private account', 'type' => 'cash', 'balance' => '500.00']);
        $this->actingAs($this->user)->get('/accounts')->assertDontSee('Private account');
        $this->get('/')->assertViewHas('totalBalance', '100.00');
        $this->delete('/accounts/'.$account->id)->assertForbidden();
        $this->postJson('/transactions', $this->transactionData(['account_id' => $account->id]))->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->assertSame('500.00', $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_user_cannot_access_another_users_category(): void
    {
        $other = User::factory()->create();
        $category = $other->categories()->create(['name' => 'Private category', 'type' => 'income']);
        $this->actingAs($this->user)->get('/categories')->assertDontSee('Private category');
        $this->get('/transactions')->assertDontSee('Private category');
        $this->delete('/categories/'.$category->id)->assertForbidden();
        $this->postJson('/transactions', $this->transactionData(['category_id' => $category->id]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_user_cannot_access_another_users_transaction(): void
    {
        $other = User::factory()->create();
        $account = $other->accounts()->create(['name' => 'Private account', 'type' => 'cash', 'balance' => '0.00']);
        $category = $other->categories()->create(['name' => 'Private category', 'type' => 'income']);
        $transaction = app(PostTransaction::class)->handle($other, $this->transactionData(['account_id' => $account->id, 'category_id' => $category->id]));
        $this->actingAs($this->user)->get('/transactions')->assertViewHas('transactions', fn ($rows) => $rows->isEmpty());
        $this->get('/')->assertViewHas('totalIncome', 0)->assertViewHas('recentTransactions', fn ($rows) => $rows->isEmpty());
        $this->assertFalse(Gate::allows('view', $transaction));
        $this->get('/transactions/'.$transaction->id)->assertNotFound();
        $this->delete('/transactions/'.$transaction->id)->assertNotFound();
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'user_id' => $other->id]);
    }

    public function test_user_cannot_access_another_users_budget(): void
    {
        $other = User::factory()->create();
        $category = $other->categories()->create(['name' => 'Private expense', 'type' => 'expense']);
        $budget = $other->budgets()->create($this->budgetData(['category_id' => $category->id]));
        $this->actingAs($this->user)->get('/budgets')->assertViewHas('budgets', fn ($rows) => $rows->isEmpty());
        $this->delete('/budgets/'.$budget->id)->assertForbidden();
        $this->postJson('/budgets', $this->budgetData(['category_id' => $category->id]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertSame('50.25', $budget->fresh()->amount);
    }

    public function test_unassigned_legacy_records_are_hidden_and_cannot_be_claimed(): void
    {
        $account = Account::create(['name' => 'Legacy account', 'type' => 'cash', 'balance' => '100.00']);
        $category = Category::create(['name' => 'Legacy category', 'type' => 'expense']);
        Transaction::create($this->transactionData(['account_id' => $account->id, 'category_id' => $category->id, 'type' => 'expense']));
        $budget = Budget::create($this->budgetData(['category_id' => $category->id]));
        $this->actingAs($this->user);
        $this->get('/accounts')->assertDontSee('Legacy account');
        $this->get('/categories')->assertDontSee('Legacy category');
        $this->get('/transactions')->assertViewHas('transactions', fn ($rows) => $rows->isEmpty());
        $this->get('/budgets')->assertViewHas('budgets', fn ($rows) => $rows->isEmpty());
        $this->delete('/accounts/'.$account->id)->assertForbidden();
        $this->delete('/categories/'.$category->id)->assertForbidden();
        $this->delete('/budgets/'.$budget->id)->assertForbidden();
        $this->postJson('/transactions', $this->transactionData(['account_id' => $account->id]))->assertUnprocessable();
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'user_id' => null]);
    }

    public static function invalidAmounts(): array
    {
        return ['zero' => [0], 'negative' => ['-1.00'], 'text' => ['abc'], 'precision' => ['1.001'], 'overflow' => ['10000000000000'], 'scientific' => ['1e2']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_transaction_amount_is_rejected(mixed $amount): void
    {
        $this->actingAs($this->user)->postJson('/transactions', $this->transactionData(['amount' => $amount]))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('100.00', $this->account->fresh()->balance);
    }

    public function test_invalid_transaction_type_category_combination_is_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/transactions', $this->transactionData(['category_id' => $this->expense->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->assertDatabaseCount('transactions', 0);
    }

    public static function invalidMonths(): array
    {
        return [[0], [13], ['1.5'], ['bad']];
    }

    #[DataProvider('invalidMonths')]
    public function test_invalid_budget_month_is_rejected(mixed $month): void
    {
        $this->actingAs($this->user)->postJson('/budgets', $this->budgetData(['month' => $month]))
            ->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_budget_requires_an_expense_category_and_positive_amount(): void
    {
        $this->actingAs($this->user)->postJson('/budgets', $this->budgetData(['category_id' => $this->income->id, 'amount' => '-1']))
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'amount']);
    }

    public function test_saving_a_budget_updates_the_existing_period(): void
    {
        $this->actingAs($this->user)->post('/budgets', $this->budgetData())->assertRedirect('/budgets');
        $this->post('/budgets', $this->budgetData(['amount' => '75.50']))->assertRedirect('/budgets');
        $this->assertDatabaseCount('budgets', 1);
        $this->assertDatabaseHas('budgets', ['category_id' => $this->expense->id, 'month' => 10, 'year' => 2026, 'amount' => '75.50', 'user_id' => $this->user->id]);
    }

    public function test_budget_uniqueness_is_enforced_by_mysql(): void
    {
        $this->user->budgets()->create($this->budgetData());
        try {
            $this->user->budgets()->create($this->budgetData());
            $this->fail('MySQL accepted a duplicate budget period.');
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]);
        }
        $this->assertDatabaseCount('budgets', 1);
    }

    public function test_creating_income_increases_the_balance_and_sets_ownership(): void
    {
        $other = User::factory()->create();
        $this->actingAs($this->user)->post('/transactions', $this->transactionData(['user_id' => $other->id]))->assertRedirect('/transactions');
        $this->assertSame('110.25', $this->account->fresh()->balance);
        $this->assertDatabaseHas('transactions', ['account_id' => $this->account->id, 'amount' => '10.25', 'user_id' => $this->user->id]);
    }

    public function test_creating_expense_decreases_the_balance(): void
    {
        $this->actingAs($this->user)->post('/transactions', $this->transactionData(['type' => 'expense', 'category_id' => $this->expense->id]))->assertRedirect('/transactions');
        $this->assertSame('89.75', $this->account->fresh()->balance);
    }

    public function test_decimal_posting_is_exact_and_locks_referenced_rows(): void
    {
        DB::enableQueryLog();
        try {
            $this->actingAs($this->user)->post('/transactions', $this->transactionData(['amount' => '0.10']))->assertRedirect('/transactions');
            $this->post('/transactions', $this->transactionData(['amount' => '0.20']))->assertRedirect('/transactions');
            $locks = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'for update'));
            $this->assertCount(4, $locks);
            $this->assertSame('100.30', $this->account->fresh()->balance);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_failed_balance_update_rolls_back_the_inserted_transaction(): void
    {
        $this->account->update(['balance' => '9999999999999.99']);
        $level = DB::transactionLevel();
        try {
            app(PostTransaction::class)->handle($this->user, $this->transactionData(['amount' => '0.01']));
            $this->fail('MySQL accepted a balance outside DECIMAL(15,2).');
        } catch (QueryException $exception) {
            $this->assertSame(1264, $exception->errorInfo[1]);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('9999999999999.99', $this->account->fresh()->balance);
    }

    public function test_account_and_category_deletion_preserves_transaction_history(): void
    {
        $transaction = app(PostTransaction::class)->handle($this->user, $this->transactionData());
        $this->actingAs($this->user)->deleteJson('/accounts/'.$this->account->id)->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->deleteJson('/categories/'.$this->income->id)->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
        $this->assertDatabaseHas('accounts', ['id' => $this->account->id, 'balance' => '110.25']);
        $this->assertDatabaseHas('categories', ['id' => $this->income->id]);
    }

    public function test_mysql_foreign_keys_prevent_direct_deletion_of_referenced_entities(): void
    {
        app(PostTransaction::class)->handle($this->user, $this->transactionData());
        foreach ([$this->account, $this->income] as $record) {
            try {
                $record->delete();
                $this->fail('MySQL allowed a referenced financial entity to be deleted.');
            } catch (QueryException $exception) {
                $this->assertSame(1451, $exception->errorInfo[1]);
            }
        }
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_category_with_budget_cannot_be_deleted(): void
    {
        $budget = $this->user->budgets()->create($this->budgetData());
        $this->actingAs($this->user)->deleteJson('/categories/'.$this->expense->id)->assertUnprocessable();
        try {
            $this->expense->delete();
            $this->fail('MySQL allowed a budget category to be deleted.');
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
        }
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_unused_owned_accounts_categories_and_budgets_can_be_deleted(): void
    {
        $budget = $this->user->budgets()->create($this->budgetData());
        $this->actingAs($this->user)->delete('/budgets/'.$budget->id)->assertRedirect('/budgets');
        $this->delete('/accounts/'.$this->account->id)->assertRedirect('/accounts');
        $this->delete('/categories/'.$this->expense->id)->assertRedirect('/categories');
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
        $this->assertDatabaseMissing('accounts', ['id' => $this->account->id]);
        $this->assertDatabaseMissing('categories', ['id' => $this->expense->id]);
    }

    public function test_account_and_category_validation_and_explicit_fields(): void
    {
        $other = User::factory()->create();
        $this->actingAs($this->user)->postJson('/accounts', ['name' => ['bad'], 'type' => 'other', 'balance' => '-1', 'currency' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'type', 'balance', 'currency']);
        $this->postJson('/categories', ['name' => str_repeat('x', 256), 'type' => 'other', 'icon' => str_repeat('x', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'type', 'icon']);
        $this->post('/accounts', ['name' => 'New account', 'type' => 'cash', 'balance' => '1.25', 'currency' => 'USD', 'user_id' => $other->id])->assertRedirect('/accounts');
        $this->post('/categories', ['name' => 'New category', 'type' => 'expense', 'icon' => 'fa-home', 'user_id' => $other->id])->assertRedirect('/categories');
        $this->assertDatabaseHas('accounts', ['name' => 'New account', 'user_id' => $this->user->id, 'balance' => '1.25', 'currency' => 'USD']);
        $this->assertDatabaseHas('categories', ['name' => 'New category', 'user_id' => $this->user->id, 'icon' => 'fa-home']);
    }

    public function test_invalid_transaction_fields_and_filters_are_rejected(): void
    {
        $this->actingAs($this->user)->postJson('/transactions', $this->transactionData(['account_id' => 999999, 'category_id' => 999999, 'type' => 'transfer', 'date' => 'not-a-date', 'description' => str_repeat('x', 2001)]))
            ->assertUnprocessable()->assertJsonValidationErrors(['account_id', 'category_id', 'type', 'date', 'description']);
        $this->getJson('/transactions?type=other&month=13&year=1800')->assertUnprocessable()->assertJsonValidationErrors(['type', 'month', 'year']);
        $this->postJson('/budgets', $this->budgetData(['year' => 2101]))->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_validated_filters_include_the_year(): void
    {
        app(PostTransaction::class)->handle($this->user, $this->transactionData());
        app(PostTransaction::class)->handle($this->user, $this->transactionData(['date' => '2025-10-05']));
        $this->actingAs($this->user)->get('/transactions?month=10&year=2026&type=income')
            ->assertOk()->assertViewHas('transactions', fn ($rows) => $rows->count() === 1 && $rows->first()->date->year === 2026);
    }

    public function test_array_identifiers_and_types_are_rejected_before_database_lookup(): void
    {
        $this->actingAs($this->user)->postJson('/transactions', $this->transactionData([
            'account_id' => [['bad']], 'category_id' => [['bad']], 'type' => ['income'],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['account_id', 'category_id', 'type']);
        $this->postJson('/budgets', $this->budgetData(['category_id' => [['bad']]]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->getJson('/transactions?type[]=income')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_saving_a_budget_cannot_claim_an_unassigned_legacy_period(): void
    {
        $budget = Budget::create($this->budgetData());
        $this->actingAs($this->user)->postJson('/budgets', $this->budgetData(['amount' => '75.00']))->assertForbidden();
        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'user_id' => null, 'amount' => '50.25']);
        $this->assertDatabaseCount('budgets', 1);
    }
}
