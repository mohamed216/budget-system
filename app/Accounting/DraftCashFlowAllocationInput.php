<?php

namespace App\Accounting;

use Illuminate\Validation\ValidationException;

final readonly class DraftCashFlowAllocationInput
{
    private function __construct(
        public int $debitLineIndex,
        public int $creditLineIndex,
        public DecimalAmount $amount,
        public ?CashFlowActivityCategory $category,
    ) {}

    public static function fromArray(mixed $value, int $index): self
    {
        if (! is_array($value) || count($value) !== 4
            || ! array_key_exists('debit_line_index', $value)
            || ! array_key_exists('credit_line_index', $value)
            || ! array_key_exists('amount', $value)
            || ! array_key_exists('category', $value)) {
            throw ValidationException::withMessages(["allocations.$index" => 'Provide one complete allocation.']);
        }
        foreach (['debit_line_index', 'credit_line_index'] as $key) {
            if (! is_int($value[$key]) || $value[$key] < 0 || $value[$key] === PHP_INT_MAX) {
                throw ValidationException::withMessages(["allocations.$index.$key" => 'Select a submitted journal line.']);
            }
        }
        $parsed = HistoricalCashFlowAllocationInput::fromArray([
            'debit_line_id' => $value['debit_line_index'] + 1,
            'credit_line_id' => $value['credit_line_index'] + 1,
            'amount' => $value['amount'], 'category' => $value['category'],
        ], $index);

        return new self($value['debit_line_index'], $value['credit_line_index'],
            $parsed->amount, $parsed->category);
    }

    /** @param array<int, \App\Models\JournalLine> $lines */
    public function forSavedLines(array $lines, int $index): HistoricalCashFlowAllocationInput
    {
        if (! isset($lines[$this->debitLineIndex], $lines[$this->creditLineIndex])) {
            throw ValidationException::withMessages(["allocations.$index" => 'Allocation references a missing submitted line.']);
        }

        return HistoricalCashFlowAllocationInput::fromArray([
            'debit_line_id' => $lines[$this->debitLineIndex]->id,
            'credit_line_id' => $lines[$this->creditLineIndex]->id,
            'amount' => $this->amount->toDecimal(), 'category' => $this->category?->value,
        ], $index);
    }
}
