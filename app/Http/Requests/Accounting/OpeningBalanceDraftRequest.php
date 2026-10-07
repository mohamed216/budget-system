<?php

namespace App\Http\Requests\Accounting;

use App\Accounting\DecimalAmount;
use App\Models\OpeningBalanceBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OpeningBalanceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        if ($this->route('openingBalance') !== null) {
            OpeningBalanceBatch::ownedBy($this->user())->findOrFail($this->route('openingBalance'));
        }

        return true;
    }

    public function rules(): array
    {
        $money = function (string $attribute, mixed $value, \Closure $fail): void {
            try {
                DecimalAmount::fromString($value);
            } catch (InvalidArgumentException) {
                $fail('Amounts must be exact decimal strings within DECIMAL(15,2).');
            }
        };

        return [
            'opening_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D', Rule::in([config('accounting.currency')])],
            'lines' => ['required', 'array', 'min:2', 'max:65535'],
            'lines.*' => ['required', 'array:chart_account_id,debit,credit'],
            'lines.*.chart_account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['bail', 'required', 'string', $money],
            'lines.*.credit' => ['bail', 'required', 'string', $money],
            'id' => ['missing'], 'user_id' => ['missing'], 'status' => ['missing'],
            'journal_entry_id' => ['missing'], 'posted_at' => ['missing'],
            'created_at' => ['missing'], 'updated_at' => ['missing'],
        ];
    }
}
