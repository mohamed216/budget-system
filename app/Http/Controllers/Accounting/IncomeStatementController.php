<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Queries\IncomeStatementQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\IncomeStatementRequest;
use App\Models\ChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class IncomeStatementController extends Controller
{
    public function __invoke(IncomeStatementRequest $request, IncomeStatementQuery $query): JsonResponse
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $data = $request->validated();
        $report = $query->execute($request->user(), $data['date_from'], $data['date_to']);

        return response()->json(['data' => [
            'date_from' => $report['period']['date_from'],
            'date_to' => $report['period']['date_to'],
            'currency' => $report['currency'],
            'revenue_accounts' => $report['revenue_accounts'],
            'expense_accounts' => $report['expense_accounts'],
            ...$report['totals'],
        ]]);
    }
}
