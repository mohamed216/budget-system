<?php

namespace App\Http\Requests\Accounting;

class CashFlowStatementPageRequest extends CashFlowStatementRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->hasAny(['start_date', 'end_date'])) {
            $this->merge([
                'start_date' => today()->startOfMonth()->toDateString(),
                'end_date' => today()->toDateString(),
            ]);
        }
    }
}
