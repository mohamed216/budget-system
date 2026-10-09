<?php

namespace App\Accounting;

use Illuminate\Validation\Rule;

final readonly class ChartAccountFieldRules
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9._-]+$/D'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'is_active' => ['required', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
