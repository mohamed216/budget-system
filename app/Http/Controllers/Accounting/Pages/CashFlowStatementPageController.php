<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\StatementOfCashFlowsQuery;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingConflictPresentation;
use App\Http\Requests\Accounting\CashFlowStatementPageRequest;
use App\Models\ChartAccount;
use Illuminate\Support\Facades\Gate;

class CashFlowStatementPageController extends Controller
{
    public function __invoke(CashFlowStatementPageRequest $request, StatementOfCashFlowsQuery $query,
        AccountingConflictPresentation $presentation)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $dates = $request->validated();
        try {
            $report = $query->execute($request->user(), $dates['start_date'], $dates['end_date'])->toArray();
        } catch (AccountingConflict $exception) {
            return response()->view('accounting.cash-flow', ['report' => null, 'dates' => $dates,
                'conflict' => $presentation->cashFlowStatement($exception)], 409);
        }

        return view('accounting.cash-flow', ['report' => $report, 'dates' => $dates, 'conflict' => null]);
    }
}
