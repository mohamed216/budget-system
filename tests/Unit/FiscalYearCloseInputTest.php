<?php

namespace Tests\Unit;

use App\Accounting\FiscalYearCloseInput;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FiscalYearCloseInputTest extends TestCase
{
    public function test_accepts_arbitrary_valid_fiscal_year_ranges_and_integer_shaped_account_ids(): void
    {
        foreach ([1, '123'] as $accountId) {
            $data = FiscalYearCloseInput::validate('2026-04-15', '2027-04-14', config('accounting.currency'), $accountId);
            $this->assertSame('2026-04-15', $data['start_date']);
            $this->assertSame('2027-04-14', $data['end_date']);
            $this->assertSame(config('accounting.currency'), $data['currency']);
            $this->assertSame($accountId, $data['retained_earnings_account_id']);
        }
        $this->assertSame('2026-04-15', FiscalYearCloseInput::validate('2026-04-15', '2026-04-15', config('accounting.currency'), 1)['end_date']);
    }

    public function test_rejects_invalid_dates_currency_and_noninteger_account_ids(): void
    {
        foreach ([
            ['2026-4-15', '2027-04-14', config('accounting.currency'), 1, 'start_date'],
            ['2026-02-30', '2027-04-14', config('accounting.currency'), 1, 'start_date'],
            ['2027-04-15', '2026-04-14', config('accounting.currency'), 1, 'end_date'],
            ['2026-04-15', '2027-04-14', 'usd', 1, 'currency'],
            ['2026-04-15', '2027-04-14', config('accounting.currency') === 'USD' ? 'SAR' : 'USD', 1, 'currency'],
            ['2026-04-15', '2027-04-14', config('accounting.currency'), 0, 'retained_earnings_account_id'],
            ['2026-04-15', '2027-04-14', config('accounting.currency'), -1, 'retained_earnings_account_id'],
            ['2026-04-15', '2027-04-14', config('accounting.currency'), 1.0, 'retained_earnings_account_id'],
            ['2026-04-15', '2027-04-14', config('accounting.currency'), '1.0', 'retained_earnings_account_id'],
        ] as [$start, $end, $currency, $accountId, $field]) {
            try {
                FiscalYearCloseInput::validate($start, $end, $currency, $accountId);
                $this->fail('Expected invalid fiscal-year-close input.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }
}
