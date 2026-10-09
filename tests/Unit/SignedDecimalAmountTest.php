<?php

namespace Tests\Unit;

use App\Accounting\SignedDecimalAmount;
use PHPUnit\Framework\TestCase;

class SignedDecimalAmountTest extends TestCase
{
    public function test_signed_large_aggregates_add_and_cancel_exactly(): void
    {
        $left = SignedDecimalAmount::fromAggregateString('19999999999999.98');
        $right = SignedDecimalAmount::fromAggregateString('-9999999999999.99');
        $this->assertSame('9999999999999.99', $left->add($right)->toDecimal());
        $this->assertSame('0.00', $right->add($right->negate())->toDecimal());
        $this->assertSame('-0.01', SignedDecimalAmount::difference('0.01', '0.02')->toDecimal());
        $this->assertSame('0.00', SignedDecimalAmount::fromAggregateString('-0.00')->toDecimal());
    }
}
