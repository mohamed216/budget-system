<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class StatementOfFinancialPositionPageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('as_of')) {
            $this->merge(['as_of' => today()->toDateString()]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['as_of' => ['required', 'date_format:Y-m-d']];
    }
}
