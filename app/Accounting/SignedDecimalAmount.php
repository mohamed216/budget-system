<?php

namespace App\Accounting;

/** Exact signed aggregate backed by DecimalAmount's digit arithmetic. */
final readonly class SignedDecimalAmount
{
    private function __construct(private DecimalAmount $magnitude, private bool $negative) {}

    public static function zero(): self
    {
        return new self(DecimalAmount::fromAggregateString('0.00'), false);
    }

    public static function fromAggregateString(string $value): self
    {
        $negative = str_starts_with($value, '-');
        $magnitude = DecimalAmount::fromAggregateString($negative ? substr($value, 1) : $value);

        return new self($magnitude, $negative && ! $magnitude->isZero());
    }

    public static function difference(string $debits, string $credits): self
    {
        return self::fromAggregateString($debits)->add(self::fromAggregateString('-'.$credits));
    }

    public function add(self $other): self
    {
        if ($this->negative === $other->negative) {
            return new self($this->magnitude->add($other->magnitude), $this->negative);
        }
        $comparison = $this->magnitude->compare($other->magnitude);
        if ($comparison === 0) {
            return self::zero();
        }

        return $comparison > 0
            ? new self($this->magnitude->subtract($other->magnitude), $this->negative)
            : new self($other->magnitude->subtract($this->magnitude), $other->negative);
    }

    public function negate(): self
    {
        return new self($this->magnitude, ! $this->negative && ! $this->magnitude->isZero());
    }

    public function equals(self $other): bool
    {
        return $this->negative === $other->negative && $this->magnitude->equals($other->magnitude);
    }

    public function toDecimal(): string
    {
        return ($this->negative ? '-' : '').$this->magnitude->toDecimal();
    }
}
