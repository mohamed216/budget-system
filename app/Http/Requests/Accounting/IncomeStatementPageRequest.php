<?php

namespace App\Http\Requests\Accounting;

class IncomeStatementPageRequest extends IncomeStatementRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->hasAny(['date_from', 'date_to'])) {
            $this->merge([
                'date_from' => today()->startOfMonth()->toDateString(),
                'date_to' => today()->toDateString(),
            ]);
        }
    }
}
