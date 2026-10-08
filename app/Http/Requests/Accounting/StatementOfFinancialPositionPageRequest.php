<?php

namespace App\Http\Requests\Accounting;

class StatementOfFinancialPositionPageRequest extends StatementOfFinancialPositionRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('as_of')) {
            $this->merge(['as_of' => today()->toDateString()]);
        }
    }
}
