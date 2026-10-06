<?php

namespace App\Accounting\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ChartAccountInput
{
    public static function validate(string $code, string $name, string $type, bool $isActive, ?int $parentId): array
    {
        return Validator::make([
            'code' => strtoupper(trim($code)), 'name' => trim($name), 'type' => $type,
            'is_active' => $isActive, 'parent_id' => $parentId,
        ], [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9._-]+$/D'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'is_active' => ['required', 'boolean'], 'parent_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }
}
