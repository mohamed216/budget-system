<?php

namespace App\Http\Requests;

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
            'balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d{1,13}(\.\d{1,2})?$/D'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/D'],
        ];
    }
}
