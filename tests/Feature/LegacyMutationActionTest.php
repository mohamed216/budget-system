<?php

namespace Tests\Feature;

use App\Actions\DeleteAccount;
use App\Actions\DeleteCategory;
use App\Actions\Exceptions\AccountHasTransactions;
use App\Actions\Exceptions\CategoryHasReferences;
use App\Actions\SaveBudget;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class LegacyMutationActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private Account $account;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = $this->owner->accounts()->create(['name' => 'Account', 'type' => 'bank', 'balance' => '0.00']);
        $this->category = $this->owner->categories()->create(['name' => 'Expense', 'type' => 'expense']);
        $this->actingAs($this->owner);
    }

    public function test_budget_store_updates_one_owned_period_and_preserves_response_and_exact_amount(): void
    {
        $data = ['category_id' => $this->category->id, 'amount' => '0.10', 'month' => 10, 'year' => 2026];
        $this->post('/budgets', $data)->assertRedirect('/budgets')->assertSessionHas('success', 'Budget saved');
        $this->post('/budgets', array_replace($data, ['amount' => '0.20']))->assertRedirect('/budgets');
        $this->assertDatabaseCount('budgets', 1);
        $this->assertDatabaseHas('budgets', ['user_id' => $this->owner->id, 'category_id' => $this->category->id, 'amount' => '0.20']);
    }

    public function test_budget_update_authorizes_the_passed_user_instead_of_the_ambient_user(): void
    {
        $ambient = User::factory()->create();
        $budget = $this->owner->budgets()->create([
            'category_id' => $this->category->id, 'amount' => '1.25', 'month' => 10, 'year' => 2026,
        ]);
        $this->actingAs($ambient);

        (new SaveBudget)->execute($this->owner, [
            'category_id' => $this->category->id, 'amount' => '2.35', 'month' => 10, 'year' => 2026,
        ]);

        $this->assertSame('2.35', $budget->fresh()->amount);
        $this->assertSame($this->owner->id, $budget->fresh()->user_id);
        $this->assertDatabaseCount('budgets', 1);
        $this->assertDatabaseMissing('budgets', ['user_id' => $ambient->id]);
    }

    public function test_budget_store_rejects_foreign_ineligible_and_unassigned_periods(): void
    {
        $foreign = User::factory()->create()->categories()->create(['name' => 'Foreign', 'type' => 'expense']);
        $income = $this->owner->categories()->create(['name' => 'Income', 'type' => 'income']);
        foreach ([$foreign, $income] as $category) {
            $this->postJson('/budgets', ['category_id' => $category->id, 'amount' => '1.00', 'month' => 10, 'year' => 2026])
                ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
        $legacy = Budget::create(['category_id' => $this->category->id, 'amount' => '1.00', 'month' => 10, 'year' => 2026]);
        $this->postJson('/budgets', ['category_id' => $this->category->id, 'amount' => '2.00', 'month' => 10, 'year' => 2026])->assertForbidden();
        $this->assertSame('1.00', $legacy->fresh()->amount);
    }

    public function test_account_delete_preserves_owner_reference_and_response_contract(): void
    {
        $foreign = User::factory()->create()->accounts()->create(['name' => 'Foreign', 'type' => 'cash', 'balance' => '0.00']);
        $legacy = Account::create(['name' => 'Legacy', 'type' => 'cash', 'balance' => '0.00']);
        $this->delete('/accounts/'.$foreign->id)->assertForbidden();
        $this->delete('/accounts/'.$legacy->id)->assertForbidden();
        $this->owner->transactions()->create(['account_id' => $this->account->id, 'category_id' => $this->category->id, 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-09']);
        $this->deleteJson('/accounts/'.$this->account->id)->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseHas('accounts', ['id' => $this->account->id]);
        $unused = $this->owner->accounts()->create(['name' => 'Unused', 'type' => 'cash', 'balance' => '0.00']);
        $this->delete('/accounts/'.$unused->id)->assertRedirect('/accounts')->assertSessionHas('success', 'Account deleted');
        $this->assertDatabaseMissing('accounts', ['id' => $unused->id]);
    }

    public function test_category_delete_preserves_owner_reference_and_response_contract(): void
    {
        $foreign = User::factory()->create()->categories()->create(['name' => 'Foreign', 'type' => 'expense']);
        $legacy = Category::create(['name' => 'Legacy', 'type' => 'expense']);
        $this->delete('/categories/'.$foreign->id)->assertForbidden();
        $this->delete('/categories/'.$legacy->id)->assertForbidden();
        $budget = $this->owner->budgets()->create(['category_id' => $this->category->id, 'amount' => '1.00', 'month' => 10, 'year' => 2026]);
        $this->deleteJson('/categories/'.$this->category->id)->assertUnprocessable()->assertJsonValidationErrors('category');
        $budget->delete();
        $this->owner->transactions()->create(['account_id' => $this->account->id, 'category_id' => $this->category->id, 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-09']);
        $this->deleteJson('/categories/'.$this->category->id)->assertUnprocessable()->assertJsonValidationErrors('category');
        $unused = $this->owner->categories()->create(['name' => 'Unused', 'type' => 'expense']);
        $this->delete('/categories/'.$unused->id)->assertRedirect('/categories')->assertSessionHas('success', 'Category deleted');
        $this->assertDatabaseMissing('categories', ['id' => $unused->id]);
    }

    public function test_actions_keep_transactions_and_lock_target_before_reference_checks(): void
    {
        $levels = [];
        DB::listen(function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
        });
        DB::enableQueryLog();
        try {
            $baseline = DB::transactionLevel();
            (new SaveBudget)->execute($this->owner, ['category_id' => $this->category->id, 'amount' => '1.00', 'month' => 10, 'year' => 2026]);
            $budgetQueries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
            $this->assertLockPrecedes($budgetQueries->all(), 'categories', 'budgets');
            $this->assertSame($baseline, DB::transactionLevel());
            DB::flushQueryLog();
            (new DeleteAccount)->execute($this->owner, $this->account);
            $accountQueries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
            $this->assertLockPrecedes($accountQueries->all(), 'accounts', 'transactions');
            $this->assertSame($baseline, DB::transactionLevel());
            DB::flushQueryLog();
            $budget = Budget::firstOrFail();
            $budget->delete();
            (new DeleteCategory)->execute($this->owner, $this->category);
            $categoryQueries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
            $this->assertLockPrecedes($categoryQueries->all(), 'categories', 'transactions');
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertContains($baseline + 1, $levels);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_second_connection_budget_save_waits_on_category_then_updates_one_period(): void
    {
        $this->publishFixturesForRace();
        try {
            $data = ['category_id' => $this->category->id, 'amount' => '1.25', 'month' => 10, 'year' => 2026];
            DB::beginTransaction();
            Category::whereKey($this->category->id)->lockForUpdate()->firstOrFail();
            $this->onSecondConnection(function () use ($data) {
                (new SaveBudget)->execute($this->owner, $data);
            });
            DB::commit();

            (new SaveBudget)->execute($this->owner, $data);
            (new SaveBudget)->execute($this->owner, array_replace($data, ['amount' => '2.35']));
            $this->assertSame(1, Budget::where('category_id', $this->category->id)->count());
            $this->assertSame('2.35', Budget::where('category_id', $this->category->id)->firstOrFail()->amount);
        } finally {
            $this->finishRace();
        }
    }

    public function test_second_connection_account_delete_waits_for_inserting_reference(): void
    {
        $this->publishFixturesForRace();
        try {
            DB::beginTransaction();
            $reference = $this->owner->transactions()->create(['account_id' => $this->account->id, 'category_id' => $this->category->id, 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-09']);
            $this->onSecondConnection(fn () => (new DeleteAccount)->execute($this->owner, $this->account));
            DB::commit();
            try {
                (new DeleteAccount)->execute($this->owner, $this->account);
                $this->fail('Referenced account was deleted.');
            } catch (AccountHasTransactions) {
                $this->assertSame(0, DB::transactionLevel());
            }
            $this->assertDatabaseHas('accounts', ['id' => $this->account->id]);
            $this->assertDatabaseHas('transactions', ['id' => $reference->id, 'account_id' => $this->account->id]);
        } finally {
            $this->finishRace();
        }
    }

    public function test_second_connection_category_delete_waits_for_inserting_reference(): void
    {
        $this->publishFixturesForRace();
        try {
            DB::beginTransaction();
            $reference = $this->owner->budgets()->create(['category_id' => $this->category->id, 'amount' => '1.00', 'month' => 10, 'year' => 2026]);
            $this->onSecondConnection(fn () => (new DeleteCategory)->execute($this->owner, $this->category));
            DB::commit();
            try {
                (new DeleteCategory)->execute($this->owner, $this->category);
                $this->fail('Referenced category was deleted.');
            } catch (CategoryHasReferences) {
                $this->assertSame(0, DB::transactionLevel());
            }
            $this->assertDatabaseHas('categories', ['id' => $this->category->id]);
            $this->assertDatabaseHas('budgets', ['id' => $reference->id, 'category_id' => $this->category->id]);
        } finally {
            $this->finishRace();
        }
    }

    public function test_second_connection_category_delete_waits_for_inserting_transaction(): void
    {
        $this->publishFixturesForRace();
        try {
            DB::beginTransaction();
            $reference = $this->owner->transactions()->create(['account_id' => $this->account->id, 'category_id' => $this->category->id, 'amount' => '1.00', 'type' => 'expense', 'date' => '2026-10-09']);
            $this->onSecondConnection(fn () => (new DeleteCategory)->execute($this->owner, $this->category));
            DB::commit();
            try {
                (new DeleteCategory)->execute($this->owner, $this->category);
                $this->fail('Referenced category was deleted.');
            } catch (CategoryHasReferences) {
                $this->assertSame(0, DB::transactionLevel());
            }
            $this->assertDatabaseHas('categories', ['id' => $this->category->id]);
            $this->assertDatabaseHas('transactions', ['id' => $reference->id, 'category_id' => $this->category->id]);
        } finally {
            $this->finishRace();
        }
    }

    private function publishFixturesForRace(): void
    {
        DB::commit();
        config(['database.connections.legacy_mutation_probe' => config('database.connections.mysql_testing')]);
        DB::connection('legacy_mutation_probe')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    /** @param list<string> $queries */
    private function assertLockPrecedes(array $queries, string $target, string $reference): void
    {
        $lock = array_search(true, array_map(fn ($sql) => str_contains($sql, $target) && str_contains($sql, 'for update'), $queries), true);
        $check = array_search(true, array_map(fn ($sql) => str_contains($sql, $reference), $queries), true);
        $this->assertIsInt($lock);
        $this->assertIsInt($check);
        $this->assertLessThan($check, $lock);
    }

    private function onSecondConnection(callable $operation): void
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('legacy_mutation_probe');
        try {
            try {
                $operation();
                $this->fail('Concurrent mutation should wait for the first connection.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->errorInfo[1], [1205, 3572]);
            }
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    private function finishRace(): void
    {
        DB::setDefaultConnection('mysql_testing');
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('transactions')->where('user_id', $this->owner->id)->delete();
        DB::table('budgets')->where('user_id', $this->owner->id)->delete();
        DB::table('accounts')->where('user_id', $this->owner->id)->delete();
        DB::table('categories')->where('user_id', $this->owner->id)->delete();
        DB::table('users')->where('id', $this->owner->id)->delete();
        DB::beginTransaction();
        DB::purge('legacy_mutation_probe');
    }
}
