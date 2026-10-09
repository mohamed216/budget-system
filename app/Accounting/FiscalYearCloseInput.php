<?php

namespace App\Accounting;

use Illuminate\Support\Facades\Validator;
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
        ], (new FiscalYearCloseFieldRules)->rules())->validate();

        if (! (is_int($retainedEarningsAccountId) && $retainedEarningsAccountId > 0)
            && ! (is_string($retainedEarningsAccountId) && preg_match('/^[1-9][0-9]*$/D', $retainedEarningsAccountId) === 1)) {
            throw ValidationException::withMessages(['retained_earnings_account_id' => 'The retained earnings account id must be a positive integer.']);
        }

        return $data;
    }
}
