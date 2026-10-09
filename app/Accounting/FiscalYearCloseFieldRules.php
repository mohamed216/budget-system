<?php

namespace App\Accounting;

use Illuminate\Validation\Rule;

final readonly class FiscalYearCloseFieldRules
{
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D', Rule::in([config('accounting.currency')])],
            'retained_earnings_account_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
