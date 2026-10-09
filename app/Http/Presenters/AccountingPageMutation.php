<?php

namespace App\Http\Presenters;

use App\Accounting\Exceptions\AccountingConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountingPageMutation
{
    public function __construct(private readonly AccountingConflictPresentation $presentation) {}

    public function error(Request $request, AccountingConflict $exception): RedirectResponse
    {
        return back()->withInput($request->except('_token'))
            ->withErrors(['accounting' => $this->presentation->pageMutation($exception)]);
    }

    public function handle(Request $request, callable $operation, string $destination): RedirectResponse
    {
        try {
            $operation();
        } catch (AccountingConflict $exception) {
            return $this->error($request, $exception);
        }

        return redirect($destination)->with('success', 'تم حفظ العملية بنجاح.');
    }
}
