<?php

namespace Tests\Unit;

use App\Rules\DecimalAmountRule;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecimalAmountRuleTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_accepts_the_existing_decimal_string_contract(string $amount): void
    {
        $validator = Validator::make(['amount' => $amount], ['amount' => ['bail', 'required', 'string', new DecimalAmountRule]]);

        $this->assertTrue($validator->passes(), $amount);
    }

    public static function validAmounts(): array
    {
        return [['1.20'], ['0'], ['0.00'], ['0001.20'], ['9999999999999.99']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts_with_the_existing_parser_message(mixed $amount): void
    {
        $validator = Validator::make(['amount' => $amount], ['amount' => ['bail', 'required', 'string', new DecimalAmountRule]]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('amount', $validator->errors()->toArray());
    }

    public static function invalidAmounts(): array
    {
        return [['1.234'], ['-1.00'], ['1e3'], ['1,000.00'], [' 1.00 '], [0.01], [1], ['10000000000000.00']];
    }

    public function test_rule_keeps_channel_specific_failure_messages(): void
    {
        foreach (['Amounts must be exact decimal strings within DECIMAL(15,2).', 'أدخل مبلغاً عشرياً نصياً صحيحاً بحد أقصى منزلتين عشريتين.'] as $message) {
            $validator = Validator::make(['amount' => '1.234'], ['amount' => ['required', 'string', new DecimalAmountRule($message)]]);
            $this->assertSame([$message], $validator->errors()->get('amount'));
        }
        $validator = Validator::make(['amount' => '1.234'], ['amount' => ['required', 'string', new DecimalAmountRule]]);
        $this->assertSame(['Amount must be a non-negative ordinary decimal string with at most two decimal places.'],
            $validator->errors()->get('amount'));
    }

    public function test_required_and_nullable_rules_keep_empty_values_outside_decimal_format_rule(): void
    {
        foreach ([null, ''] as $empty) {
            $required = Validator::make(['amount' => $empty], ['amount' => ['bail', 'required', 'string', new DecimalAmountRule]]);
            $this->assertTrue($required->fails());
            $this->assertArrayHasKey('Required', $required->failed()['amount']);
        }
        $nullable = Validator::make(['amount' => null], ['amount' => ['nullable', 'string', new DecimalAmountRule]]);
        $this->assertTrue($nullable->passes());
    }
}
