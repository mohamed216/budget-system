<?php

namespace App\Http\Requests;

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
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'regex:/^\d{1,13}(\.\d{1,2})?$/D'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:1900,2100'],
        ];
    }
}
