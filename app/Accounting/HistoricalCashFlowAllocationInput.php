<?php

namespace App\Accounting;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class HistoricalCashFlowAllocationInput
{
    private function __construct(
        public int $debitLineId,
        public int $creditLineId,
        public DecimalAmount $amount,
        public ?CashFlowActivityCategory $category,
    ) {}

    public static function fromArray(mixed $value, int $index): self
    {
        if (! is_array($value) || count($value) !== 4
            || ! array_key_exists('debit_line_id', $value)
            || ! array_key_exists('credit_line_id', $value)
            || ! array_key_exists('amount', $value)
            || ! array_key_exists('category', $value)) {
            throw ValidationException::withMessages(["allocations.$index" => 'Provide one complete allocation.']);
        }
        foreach (['debit_line_id', 'credit_line_id'] as $key) {
            if (! is_int($value[$key]) || $value[$key] < 1) {
                throw ValidationException::withMessages(["allocations.$index.$key" => 'Select a valid journal line.']);
            }
        }
        try {
            $amount = DecimalAmount::fromString($value['amount']);
            if (! $amount->isPositive()) {
                throw new InvalidArgumentException();
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(["allocations.$index.amount" => 'Enter a positive exact decimal amount.']);
        }
        $category = $value['category'];
        if ($category !== null && (! is_string($category) || CashFlowActivityCategory::tryFrom($category) === null)) {
            throw ValidationException::withMessages(["allocations.$index.category" => 'Select a valid cash flow category.']);
        }

        return new self($value['debit_line_id'], $value['credit_line_id'], $amount,
            $category === null ? null : CashFlowActivityCategory::from($category));
    }
}
