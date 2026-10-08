<?php

namespace Tests\Unit;

use App\Accounting\DecimalAmount;
use App\Support\MoneyDisplay;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyDisplayTest extends TestCase
{
    public function test_formats_exact_small_zero_and_large_decimal_strings(): void
    {
        $this->assertSame('0.00', MoneyDisplay::format('0'));
        $this->assertSame('1,234.05', MoneyDisplay::format('1234.05'));
        $this->assertSame('99,999,999,999,999.99', MoneyDisplay::format('99999999999999.99'));
        $this->assertSame('-99,999,999,999,999.98', MoneyDisplay::format('-99999999999999.98'));
    }

    public function test_aggregate_decimal_subtraction_is_exact_beyond_storage_limit(): void
    {
        $large = DecimalAmount::fromAggregateString('99999999999999.99');
        $cent = DecimalAmount::fromString('0.01');

        $this->assertSame('99999999999999.98', $large->subtract($cent)->toDecimal());
        $this->assertSame('99999999999999.99', $large->toDecimal());
    }

    public function test_formatter_rejects_non_decimal_input_and_floats(): void
    {
        foreach ([1.2, '1e2', '1,000.00'] as $amount) {
            try {
                MoneyDisplay::format($amount);
                $this->fail('Invalid money was formatted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
