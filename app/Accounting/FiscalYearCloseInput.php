<?php

namespace App\Accounting;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalYearCloseInput
{
    public static function validate(string $startDate, string $endDate, string $currency, mixed $retainedEarningsAccountId): array
    {
        $data = Validator::make([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'currency' => $currency,
            'retained_earnings_account_id' => $retainedEarningsAccountId,
        ], [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D', Rule::in([config('accounting.currency')])],
            'retained_earnings_account_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        if (! (is_int($retainedEarningsAccountId) && $retainedEarningsAccountId > 0)
            && ! (is_string($retainedEarningsAccountId) && preg_match('/^[1-9][0-9]*$/D', $retainedEarningsAccountId) === 1)) {
            throw ValidationException::withMessages(['retained_earnings_account_id' => 'The retained earnings account id must be a positive integer.']);
        }

        return $data;
    }
}
