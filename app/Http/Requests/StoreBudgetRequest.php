<?php

namespace App\Http\Requests;

use App\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['bail', 'required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->where('type', 'expense')],
            'amount' => ['bail', 'required', 'string', new DecimalAmountRule('The amount field must be an exact positive decimal string within DECIMAL(15,2).', positive: true, maxWholeDigits: 13)],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:1900,2100'],
        ];
    }
}
