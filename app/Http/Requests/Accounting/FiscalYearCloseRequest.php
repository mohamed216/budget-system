<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\FiscalYearCloseFieldRules;
use Illuminate\Foundation\Http\FormRequest;

class FiscalYearCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_merge((new FiscalYearCloseFieldRules)->rules(), [
            'id' => ['missing'], 'user_id' => ['missing'], 'status' => ['missing'],
            'journal_entry_id' => ['missing'], 'closed_at' => ['missing'],
            'created_at' => ['missing'], 'updated_at' => ['missing'],
        ]);
    }
}
