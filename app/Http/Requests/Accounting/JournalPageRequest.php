<?php

namespace App\Http\Requests\Accounting;

class JournalPageRequest extends JournalDraftRequest
{
    protected function prepareForValidation(): void
    {
        // Native HTML forms omit collections when their last row is removed.
        if (! $this->exists('lines')) {
            $this->merge(['lines' => []]);
        }
    }
}
