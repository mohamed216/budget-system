<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\ChartAccountFieldRules;
use Illuminate\Foundation\Http\FormRequest;

class ChartAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => $field === 'code' ? strtoupper(trim($value)) : trim($value)]);
            }
        }
    }

    public function rules(): array
    {
        return array_merge((new ChartAccountFieldRules)->rules(), [
            'user_id' => ['missing'], 'id' => ['missing'],
        ]);
    }
}
