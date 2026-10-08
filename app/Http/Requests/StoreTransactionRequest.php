<?php

namespace App\Http\Requests;

use App\Rules\DecimalAmountRule;
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
            'amount' => ['bail', 'required', 'string', new DecimalAmountRule('The amount field must be an exact positive decimal string within DECIMAL(15,2).', positive: true, maxWholeDigits: 13)],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
