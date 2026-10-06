<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\DecimalAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class JournalDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $money = function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                DecimalAmount::fromString($value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        };

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
            'user_id' => ['missing'], 'status' => ['missing'], 'posted_at' => ['missing'],
            'id' => ['missing'], 'line_number' => ['missing'], 'journal_entry_id' => ['missing'],
        ];
    }
}
