<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\CashFlowActivityCategory;
use App\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JournalDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $allocations = $this->input('allocations');
        if (! is_array($allocations)) {
            return;
        }
        foreach ($allocations as &$allocation) {
            if (! is_array($allocation)) {
                continue;
            }
            foreach (['debit_line_index', 'credit_line_index'] as $key) {
                $value = $allocation[$key] ?? null;
                if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1
                    && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                    $allocation[$key] = (int) $value;
                }
            }
            if (($allocation['category'] ?? null) === '') {
                $allocation['category'] = null;
            }
        }
        unset($allocation);
        $this->merge(['allocations' => $allocations]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $money = new DecimalAmountRule;

        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'size:3', Rule::in([config('accounting.currency')])],
            'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string'],
            'version' => $this->route('journal') !== null ? ['required', 'integer', 'min:1'] : ['missing'],
            'lines' => ['present', 'array', 'max:65535'],
            'lines.*' => ['required', 'array:chart_account_id,debit,credit,description'],
            'lines.*.chart_account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['bail', 'required', 'string', $money],
            'lines.*.credit' => ['bail', 'required', 'string', $money],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['sometimes', 'array', 'max:65535'],
            'allocations.*' => ['required', 'array:debit_line_index,credit_line_index,amount,category'],
            'allocations.*.debit_line_index' => ['required', 'integer', 'min:0'],
            'allocations.*.credit_line_index' => ['required', 'integer', 'min:0'],
            'allocations.*.amount' => ['bail', 'required', 'string', $money],
            'allocations.*.category' => ['present', 'nullable', 'string', Rule::in(array_column(CashFlowActivityCategory::cases(), 'value'))],
            'user_id' => ['missing'], 'status' => ['missing'], 'posted_at' => ['missing'],
            'id' => ['missing'], 'line_number' => ['missing'], 'journal_entry_id' => ['missing'],
        ];
    }
}
