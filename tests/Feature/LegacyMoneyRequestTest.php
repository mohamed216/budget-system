<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class LegacyMoneyRequestTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private int $accountId;

    private int $incomeCategoryId;

    private int $expenseCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->accountId = $this->owner->accounts()->create([
            'name' => 'Owned account', 'type' => 'bank', 'balance' => '0.00', 'currency' => 'SAR',
        ])->id;
        $this->incomeCategoryId = $this->owner->categories()->create(['name' => 'Income', 'type' => 'income'])->id;
        $this->expenseCategoryId = $this->owner->categories()->create(['name' => 'Expense', 'type' => 'expense'])->id;
        $this->actingAs($this->owner);
    }

    public static function validAccountAmounts(): array
    {
        return [
            ['0', '0.00'], ['0.00', '0.00'], ['1', '1.00'], ['1.2', '1.20'],
            ['1.23', '1.23'], ['9999999999999.99', '9999999999999.99'],
            [' 1.23 ', '1.23'],
        ];
    }

    #[DataProvider('validAccountAmounts')]
    public function test_account_balance_accepts_exact_strings_and_persists_exactly(string $input, string $expected): void
    {
        $this->post('/accounts', $this->accountData($input))->assertRedirect('/accounts');
        $this->assertDatabaseHas('accounts', ['user_id' => $this->owner->id, 'name' => 'New account', 'balance' => $expected]);
    }

    public static function validPositiveAmounts(): array
    {
        return [
            ['1', '1.00'], ['1.2', '1.20'], ['1.23', '1.23'],
            ['9999999999999.99', '9999999999999.99'], [' 1.23 ', '1.23'],
        ];
    }

    #[DataProvider('validPositiveAmounts')]
    public function test_budget_amount_accepts_exact_strings_and_persists_exactly(string $input, string $expected): void
    {
        $this->post('/budgets', $this->budgetData($input))->assertRedirect('/budgets');
        $this->assertDatabaseHas('budgets', ['user_id' => $this->owner->id, 'category_id' => $this->expenseCategoryId, 'amount' => $expected]);
    }

    #[DataProvider('validPositiveAmounts')]
    public function test_transaction_amount_accepts_exact_strings_and_persists_exactly(string $input, string $expected): void
    {
        $this->post('/transactions', $this->transactionData($input))->assertRedirect('/transactions');
        $this->assertDatabaseHas('transactions', ['user_id' => $this->owner->id, 'account_id' => $this->accountId, 'amount' => $expected]);
        $this->assertDatabaseHas('accounts', ['id' => $this->accountId, 'balance' => $expected]);
    }

    public static function invalidAmounts(): array
    {
        return [
            'negative' => ['-1'], 'precision' => ['1.234'], 'scientific' => ['1e2'],
            'comma' => ['1,000.00'], 'empty' => [''], 'null' => [null],
            'storage overflow' => ['10000000000000'], 'excess leading digits' => ['00000000000001.00'],
            'JSON integer' => [1], 'JSON decimal' => [1.2],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_account_balance_rejects_invalid_or_numeric_json_input(mixed $input): void
    {
        $this->postJson('/accounts', $this->accountData($input))->assertUnprocessable()->assertJsonValidationErrors('balance');
        $this->assertDatabaseCount('accounts', 1);
    }

    #[DataProvider('invalidAmounts')]
    public function test_budget_amount_rejects_invalid_or_numeric_json_input(mixed $input): void
    {
        $this->postJson('/budgets', $this->budgetData($input))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('budgets', 0);
    }

    #[DataProvider('invalidAmounts')]
    public function test_transaction_amount_rejects_invalid_or_numeric_json_input(mixed $input): void
    {
        $this->postJson('/transactions', $this->transactionData($input))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseHas('accounts', ['id' => $this->accountId, 'balance' => '0.00']);
    }

    public function test_zero_remains_invalid_for_budget_and_transaction_but_valid_for_account(): void
    {
        $this->postJson('/budgets', $this->budgetData('0.00'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/transactions', $this->transactionData('0'))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->post('/accounts', $this->accountData('0'))->assertRedirect('/accounts');
        $this->assertDatabaseHas('accounts', ['user_id' => $this->owner->id, 'name' => 'New account', 'balance' => '0.00']);
    }

    private function accountData(mixed $balance): array
    {
        return ['name' => 'New account', 'type' => 'cash', 'currency' => 'SAR', 'balance' => $balance];
    }

    private function budgetData(mixed $amount): array
    {
        return ['category_id' => $this->expenseCategoryId, 'month' => 10, 'year' => 2026, 'amount' => $amount];
    }

    private function transactionData(mixed $amount): array
    {
        return [
            'account_id' => $this->accountId, 'category_id' => $this->incomeCategoryId,
            'type' => 'income', 'date' => '2026-10-09', 'amount' => $amount,
        ];
    }
}
