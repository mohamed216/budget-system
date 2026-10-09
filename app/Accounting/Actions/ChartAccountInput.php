<?php

namespace App\Accounting\Actions;

use App\Accounting\ChartAccountFieldRules;
use Illuminate\Support\Facades\Validator;

final class ChartAccountInput
{
    public static function validate(string $code, string $name, string $type, bool $isActive, ?int $parentId): array
    {
        return Validator::make([
            'code' => strtoupper(trim($code)), 'name' => trim($name), 'type' => $type,
            'is_active' => $isActive, 'parent_id' => $parentId,
        ], (new ChartAccountFieldRules)->rules())->validate();
    }
}
