<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FiscalYearCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D', Rule::in([config('accounting.currency')])],
            'retained_earnings_account_id' => ['required', 'integer', 'min:1'],
            'id' => ['missing'], 'user_id' => ['missing'], 'status' => ['missing'],
            'journal_entry_id' => ['missing'], 'closed_at' => ['missing'],
            'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
