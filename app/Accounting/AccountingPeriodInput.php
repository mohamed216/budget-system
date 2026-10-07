<?php

namespace App\Accounting;

use Illuminate\Support\Facades\Validator;

final class AccountingPeriodInput
{
    public static function dates(string $startDate, string $endDate): array
    {
        return Validator::make(['start_date' => $startDate, 'end_date' => $endDate], [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ])->validate();
    }
}
