<?php

namespace Tests\Unit;

use App\Accounting\CashFlowActivityCategory;
use App\Accounting\HistoricalCashFlowAllocationInput;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HistoricalCashFlowAllocationInputTest extends TestCase
{
    public function test_exact_amount_and_category_are_typed(): void
    {
        $row = HistoricalCashFlowAllocationInput::fromArray([
            'category' => 'investing', 'amount' => '100.01',
            'credit_line_id' => 2, 'debit_line_id' => 1,
        ], 0);

        $this->assertSame(1, $row->debitLineId);
        $this->assertSame(2, $row->creditLineId);
        $this->assertSame('100.01', $row->amount->toDecimal());
        $this->assertSame(CashFlowActivityCategory::Investing, $row->category);
    }

    public function test_invalid_amount_category_and_shape_are_rejected(): void
    {
        $valid = ['debit_line_id' => 1, 'credit_line_id' => 2, 'amount' => '1.00', 'category' => null];
        foreach ([
            ['amount', 1.0], ['amount', '1e0'], ['amount', '0.00'],
            ['category', 'OPERATING'], ['debit_line_id', '1'],
        ] as [$key, $value]) {
            try {
                HistoricalCashFlowAllocationInput::fromArray(array_replace($valid, [$key => $value]), 0);
                $this->fail('Expected allocation input validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey("allocations.0.$key", $exception->errors());
            }
        }
        try {
            HistoricalCashFlowAllocationInput::fromArray(array_merge($valid, ['extra' => 'value']), 0);
            $this->fail('Expected shape validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocations.0', $exception->errors());
        }
    }
}
