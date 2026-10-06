<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $type = is_string($this->input('type')) ? $this->input('type') : '';

        return [
            'account_id' => ['bail', 'required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'category_id' => ['bail', 'required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->user()->id)->where('type', $type)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99', 'regex:/^\d{1,13}(\.\d{1,2})?$/D'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
