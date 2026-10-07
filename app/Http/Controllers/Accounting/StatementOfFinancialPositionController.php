<?php

namespace App\Http\Controllers\Accounting;

use App\Accounting\Queries\StatementOfFinancialPositionQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StatementOfFinancialPositionRequest;
use App\Models\ChartAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StatementOfFinancialPositionController extends Controller
{
    public function __invoke(StatementOfFinancialPositionRequest $request, StatementOfFinancialPositionQuery $query): JsonResponse
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $data = $request->validated();
        $report = $query->execute($request->user(), $data['as_of']);

        return response()->json(['data' => [
            'as_of' => $report['as_of'],
            'currency' => $report['currency'],
            'assets' => $report['assets'],
            'liabilities' => $report['liabilities'],
            'equity' => $report['equity'],
            'total_assets' => $report['totals']['assets'],
            'total_liabilities' => $report['totals']['liabilities'],
            'total_equity' => $report['totals']['equity'],
            'unclosed_cumulative_profit_loss' => $report['totals']['unclosed_cumulative_profit_loss'],
            'liabilities_equity_and_unclosed_profit_loss' => $report['totals']['liabilities_equity_and_unclosed_profit_loss'],
            'equation_difference' => $report['totals']['equation_difference'],
        ]]);
    }
}
