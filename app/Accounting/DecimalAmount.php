<?php

declare(strict_types=1);

namespace App\Accounting;

use InvalidArgumentException;

final readonly class DecimalAmount
{
    private const MAX_MINOR_UNITS = '999999999999999';

    private function __construct(private string $minorUnits) {}

    /** Parse one DECIMAL(15,2) amount. Aggregate results may exceed that range. */
    public static function fromString(mixed $value): self
    {
        $amount = self::fromAggregateString($value);
        if (self::compareDigits($amount->minorUnits, self::MAX_MINOR_UNITS) > 0) {
            throw new InvalidArgumentException('Amount exceeds DECIMAL(15,2) maximum 9999999999999.99.');
        }

        return $amount;
    }

    /** Parse an exact nonnegative DECIMAL aggregate without the single-row storage limit. */
    public static function fromAggregateString(mixed $value): self
    {
        if (! is_string($value) || preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('Amount must be a non-negative ordinary decimal string with at most two decimal places.');
        }

        $parts = explode('.', $value, 2);
        $minorUnits = ltrim($parts[0].str_pad($parts[1] ?? '', 2, '0'), '0');
        $minorUnits = $minorUnits === '' ? '0' : $minorUnits;
        return new self($minorUnits);
    }

    /** Exact, unbounded aggregate addition; neither operand is changed. */
    public function add(self $other): self
    {
        $left = strlen($this->minorUnits) - 1;
        $right = strlen($other->minorUnits) - 1;
        $carry = 0;
        $digits = [];
        while ($left >= 0 || $right >= 0 || $carry > 0) {
            $sum = $carry;
            if ($left >= 0) {
                $sum += ord($this->minorUnits[$left--]) - 48;
            }
            if ($right >= 0) {
                $sum += ord($other->minorUnits[$right--]) - 48;
            }
            $digits[] = (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        return new self(implode('', array_reverse($digits)));
    }

    /** Exact, nonnegative aggregate subtraction; neither operand is changed. */
    public function subtract(self $other): self
    {
        if ($this->compare($other) < 0) {
            throw new InvalidArgumentException('Amount subtraction cannot produce a negative result.');
        }

        $left = strlen($this->minorUnits) - 1;
        $right = strlen($other->minorUnits) - 1;
        $borrow = 0;
        $digits = [];
        while ($left >= 0) {
            $digit = ord($this->minorUnits[$left--]) - 48 - $borrow;
            if ($right >= 0) {
                $digit -= ord($other->minorUnits[$right--]) - 48;
            }
            $borrow = $digit < 0 ? 1 : 0;
            $digits[] = (string) ($digit < 0 ? $digit + 10 : $digit);
        }

        $minorUnits = ltrim(implode('', array_reverse($digits)), '0');

        return new self($minorUnits === '' ? '0' : $minorUnits);
    }

    /** Return -1, 0, or 1 without numeric-string coercion. */
    public function compare(self $other): int
    {
        return self::compareDigits($this->minorUnits, $other->minorUnits);
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === '0';
    }

    public function isPositive(): bool
    {
        return ! $this->isZero();
    }

    public function toDecimal(): string
    {
        $digits = str_pad($this->minorUnits, 3, '0', STR_PAD_LEFT);

        return substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    private static function compareDigits(string $left, string $right): int
    {
        $lengthComparison = strlen($left) <=> strlen($right);

        return $lengthComparison !== 0 ? $lengthComparison : (strcmp($left, $right) <=> 0);
    }
}
