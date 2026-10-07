<?php

namespace Tests\Unit;

use App\Accounting\OpeningBalanceTotals;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OpeningBalanceTotalsTest extends TestCase
{
    public function test_exact_cents_and_maximum_decimal_balance_without_floats(): void
    {
        $total = OpeningBalanceTotals::assertBalanced([
            ['debit' => '0.01', 'credit' => '0.00'],
            ['debit' => '9999999999999.99', 'credit' => '0.00'],
            ['debit' => '0.00', 'credit' => '9999999999999.99'],
            ['debit' => '0.00', 'credit' => '0.01'],
        ]);
        $this->assertSame('10000000000000.00', $total->toDecimal());
    }

    public function test_unbalanced_zero_and_invalid_sides_are_rejected(): void
    {
        foreach ([
            [],
            [['debit' => '1.00', 'credit' => '0.00']],
            [['debit' => '0.00', 'credit' => '0.00'], ['debit' => '0.00', 'credit' => '0.00']],
            [['debit' => '1.00', 'credit' => '1.00'], ['debit' => '0.00', 'credit' => '1.00']],
            [['debit' => '1.01', 'credit' => '0.00'], ['debit' => '0.00', 'credit' => '1.00']],
            [['debit' => 1.00, 'credit' => '0.00'], ['debit' => '0.00', 'credit' => '1.00']],
        ] as $lines) {
            try {
                OpeningBalanceTotals::assertBalanced($lines);
                $this->fail('Expected invalid opening balance total.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
