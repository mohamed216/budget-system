<?php

namespace App\Http\Requests;

use App\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['cash', 'bank', 'wallet'])],
            'balance' => ['bail', 'required', 'string', new DecimalAmountRule('The balance field must be an exact non-negative decimal string within DECIMAL(15,2).', maxWholeDigits: 13)],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/D'],
        ];
    }
}
