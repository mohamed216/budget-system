<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class IncomeStatementPageRequest extends FormRequest
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

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
