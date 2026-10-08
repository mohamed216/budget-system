<?php

namespace App\Http\Presenters;

use App\Accounting\Exceptions\AccountingConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountingPageMutation
{
    public function handle(Request $request, callable $operation, string $destination): RedirectResponse
    {
        try {
            $operation();
        } catch (AccountingConflict $exception) {
            return back()->withInput($request->except('_token'))->withErrors(['accounting' => $exception->getMessage()]);
        }

        return redirect($destination)->with('success', 'تم حفظ العملية بنجاح.');
    }
}
