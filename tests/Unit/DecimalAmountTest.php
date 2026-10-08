<?php

namespace Tests\Unit;

use App\Accounting\DecimalAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalAmountTest extends TestCase
{
    #[DataProvider('validAmounts')]
    public function test_normalizes_valid_amounts(string $input, string $expected): void
    {
        $this->assertSame($expected, DecimalAmount::fromString($input)->toDecimal());
    }

    public static function validAmounts(): array
    {
        return [
            ['0', '0.00'], ['0.01', '0.01'], ['1', '1.00'],
            ['1.2', '1.20'], ['1.23', '1.23'], ['0001.20', '1.20'],
            ['000.00', '0.00'], ['9999999999999.99', '9999999999999.99'],
        ];
    }

    public function test_fractional_addition_is_exact_and_immutable(): void
    {
        $left = DecimalAmount::fromString('0.10');
        $right = DecimalAmount::fromString('0.20');
        $this->assertSame('0.30', $left->add($right)->toDecimal());
        $this->assertSame('0.10', $left->toDecimal());
        $this->assertSame('0.20', $right->toDecimal());
        $total = DecimalAmount::fromString('0');
        for ($i = 0; $i < 1000; $i++) {
            $total = $total->add(DecimalAmount::fromString('0.01'));
        }
        $this->assertSame('10.00', $total->toDecimal());
        $this->assertSame('1.00', DecimalAmount::fromString('0.99')->add(DecimalAmount::fromString('0.01'))->toDecimal());
        $this->assertSame('0.00', DecimalAmount::fromString('0')->add(DecimalAmount::fromString('0'))->toDecimal());
    }

    #[DataProvider('nonnegativeSubtractions')]
    public function test_nonnegative_subtraction_is_exact_and_canonical(string $left, string $right, string $expected): void
    {
        $greater = DecimalAmount::fromString($left);
        $lesser = DecimalAmount::fromString($right);

        $this->assertSame($expected, $greater->subtract($lesser)->toDecimal());
        $this->assertSame(DecimalAmount::fromString($left)->toDecimal(), $greater->toDecimal());
        $this->assertSame(DecimalAmount::fromString($right)->toDecimal(), $lesser->toDecimal());
    }

    public static function nonnegativeSubtractions(): array
    {
        return [
            ['10.00', '3.25', '6.75'],
            ['1000.00', '0.01', '999.99'],
            ['1000000000000.00', '999999999999.99', '0.01'],
            ['1.23', '1.23', '0.00'],
            ['9999999999999.99', '0.01', '9999999999999.98'],
            ['10.00', '0.00', '10.00'],
            ['00010.00', '0003.25', '6.75'],
        ];
    }

    public function test_subtraction_rejects_a_negative_result(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalAmount::fromString('3.25')->subtract(DecimalAmount::fromString('10.00'));
    }

    public function test_subtraction_accepts_exact_aggregate_values_above_storage_range(): void
    {
        $maximum = DecimalAmount::fromString('9999999999999.99');
        $aggregate = $maximum->add($maximum);

        $this->assertSame('9999999999999.99', $aggregate->subtract($maximum)->toDecimal());
        for ($i = 0; $i < 64; $i++) {
            $aggregate = $aggregate->add($aggregate);
        }
        $this->assertSame('0.00', $aggregate->subtract($aggregate)->toDecimal());
        $this->assertSame($aggregate->toDecimal(), $aggregate->add($maximum)->subtract($maximum)->toDecimal());
    }

    public function test_aggregate_totals_exceed_storage_and_native_integer_ranges(): void
    {
        $maximum = DecimalAmount::fromString('9999999999999.99');
        $total = $maximum;
        for ($i = 0; $i < 64; $i++) {
            $total = $total->add($total);
        }
        $this->assertSame('184467440737095331692559262904483.84', $total->toDecimal());
        $this->assertSame(1, $total->compare($maximum));
        $this->assertTrue($total->equals($total->add(DecimalAmount::fromString('0'))));
        $this->assertSame('19999999999999.98', $maximum->add($maximum)->toDecimal());
    }

    public function test_comparison_equality_and_zero_checks(): void
    {
        $zero = DecimalAmount::fromString('0.00');
        $one = DecimalAmount::fromString('1');
        $same = DecimalAmount::fromString('0001.00');
        $this->assertTrue($zero->isZero());
        $this->assertFalse($zero->isPositive());
        $this->assertFalse($one->isZero());
        $this->assertTrue($one->isPositive());
        $this->assertTrue($one->equals($same));
        $this->assertFalse($one->equals($zero));
        $this->assertSame(0, $one->compare($same));
        $this->assertSame(-1, $zero->compare($one));
        $this->assertSame(1, $one->compare($zero));
        $this->assertSame(-1, DecimalAmount::fromString('9.99')->compare(DecimalAmount::fromString('10')));
        $this->assertSame(-1, DecimalAmount::fromString('1.01')->compare(DecimalAmount::fromString('1.02')));
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_or_out_of_range_input(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalAmount::fromString($input);
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], [
            '-1', '-0', '+1', '1e2', '1E+2', '1,000', '1,23',
            '1.234', '0.001', '', '.', '.5', '1.', '1..2', 'abc',
            ' 1', '1 ', "1\n", '1/2', '١.٢',
            '10000000000000', '10000000000000.00', '9999999999999.999',
            '999999999999999999999999999999999999', 1, 1.2, null, [], true,
        ]);
    }
}
