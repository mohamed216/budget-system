<?php

namespace App\Http\Requests\Accounting;

class JournalPageRequest extends JournalDraftRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        // Native HTML forms omit collections when their last row is removed.
        if (! $this->exists('lines')) {
            $this->merge(['lines' => []]);
        }
    }
}
