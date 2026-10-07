<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        if ($this->route('period') === null) {
            return true;
        }

        // Resolve ownership before validating an update body, including malformed bodies.
        $period = AccountingPeriod::ownedBy($this->user())->findOrFail($this->route('period'));

        return Gate::forUser($this->user())->allows('view', $period);
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'id' => ['missing'],
            'user_id' => ['missing'],
            'status' => ['missing'],
            'first_closed_at' => ['missing'],
            'created_at' => ['missing'],
            'updated_at' => ['missing'],
        ];
    }
}
