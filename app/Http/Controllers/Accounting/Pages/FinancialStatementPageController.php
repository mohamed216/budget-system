<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\IncomeStatementQuery;
use App\Accounting\Queries\StatementOfFinancialPositionQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\IncomeStatementPageRequest;
use App\Http\Requests\Accounting\StatementOfFinancialPositionPageRequest;
use App\Models\ChartAccount;
use Illuminate\Support\Facades\Gate;

class FinancialStatementPageController extends Controller
{
    public function incomeStatement(IncomeStatementPageRequest $request, IncomeStatementQuery $query)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $dates = $request->validated();
        try {
            $report = $query->execute($request->user(), $dates['date_from'], $dates['date_to']);
        } catch (AccountingConflict $exception) {
            return response()->view('accounting.income-statement', ['report' => null, 'dates' => $dates,
                'conflict' => 'تعذر عرض القائمة بسبب تعارض في بيانات القيود المرحلة. راجع العملات وتوازن القيود.'], 409);
        }

        return view('accounting.income-statement', ['report' => $report, 'dates' => $dates, 'conflict' => null]);
    }

    public function balanceSheet(StatementOfFinancialPositionPageRequest $request, StatementOfFinancialPositionQuery $query)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $date = $request->validated()['as_of'];
        try {
            $report = $query->execute($request->user(), $date);
        } catch (AccountingConflict $exception) {
            return response()->view('accounting.balance-sheet', ['report' => null, 'asOf' => $date,
                'conflict' => 'تعذر عرض القائمة بسبب تعارض في بيانات القيود المرحلة. راجع العملات وتوازن القيود.'], 409);
        }

        return view('accounting.balance-sheet', ['report' => $report, 'asOf' => $date, 'conflict' => null]);
    }
}
