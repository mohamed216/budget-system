<?php

namespace App\Http\Requests\Accounting;

class GeneralLedgerPageRequest extends GeneralLedgerRequest
{
    public function rules(): array
    {
        return $this->hasAny(['chart_account_id', 'date_from', 'date_to']) ? parent::rules() : [];
    }
}
