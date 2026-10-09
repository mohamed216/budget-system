<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\StatementOfCashFlowsQuery;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingConflictPresentation;
use App\Http\Requests\Accounting\CashFlowStatementRequest;
use App\Models\ChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CashFlowStatementController extends Controller
{
    public function __invoke(CashFlowStatementRequest $request, StatementOfCashFlowsQuery $query,
        AccountingConflictPresentation $presentation): JsonResponse
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $dates = $request->validated();
        try {
            $report = $query->execute($request->user(), $dates['start_date'], $dates['end_date']);
        } catch (AccountingConflict $exception) {
            return response()->json(['message' => $presentation->cashFlowStatement($exception)], 409);
        }

        return response()->json(['data' => $report->toArray()]);
    }
}
