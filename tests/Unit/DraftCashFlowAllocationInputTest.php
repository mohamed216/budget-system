<?php

namespace Tests\Unit;

use App\Accounting\CashFlowActivityCategory;
use App\Accounting\DraftCashFlowAllocationInput;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DraftCashFlowAllocationInputTest extends TestCase
{
    public function test_zero_based_indices_and_exact_money_are_preserved(): void
    {
        $row = DraftCashFlowAllocationInput::fromArray([
            'debit_line_index' => 0, 'credit_line_index' => 2,
            'amount' => '9999999999999.99', 'category' => 'financing',
        ], 0);
        $this->assertSame(0, $row->debitLineIndex);
        $this->assertSame(2, $row->creditLineIndex);
        $this->assertSame('9999999999999.99', $row->amount->toDecimal());
        $this->assertSame(CashFlowActivityCategory::Financing, $row->category);
    }

    public function test_float_wrong_shape_and_case_distinct_category_are_rejected(): void
    {
        $valid = ['debit_line_index' => 0, 'credit_line_index' => 1, 'amount' => '1.00', 'category' => null];
        foreach ([
            array_replace($valid, ['amount' => 1.0]),
            array_replace($valid, ['category' => 'OPERATING']),
            array_replace($valid, ['debit_line_index' => -1]),
            array_replace($valid, ['credit_line_index' => PHP_INT_MAX]),
            array_replace($valid, ['debit_line_id' => 1]),
        ] as $row) {
            try {
                DraftCashFlowAllocationInput::fromArray($row, 0);
                $this->fail('Expected invalid draft allocation input.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
