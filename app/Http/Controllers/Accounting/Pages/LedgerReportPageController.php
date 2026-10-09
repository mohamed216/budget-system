<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\GeneralLedgerQuery;
use App\Accounting\Queries\OwnedChartAccounts;
use App\Accounting\Queries\TrialBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingConflictPresentation;
use App\Http\Requests\Accounting\GeneralLedgerPageRequest;
use App\Http\Requests\Accounting\TrialBalanceRequest;
use App\Models\ChartAccount;
use Illuminate\Support\Facades\Gate;

class LedgerReportPageController extends Controller
{
    public function __construct(private readonly OwnedChartAccounts $accounts) {}

    public function ledger(GeneralLedgerPageRequest $request, GeneralLedgerQuery $query, AccountingConflictPresentation $presentation)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $report = null;
        if ($request->hasAny(['chart_account_id', 'date_from', 'date_to'])) {
            $d = $request->validated();
            $account = $this->accounts->find($request->user(), (string) $d['chart_account_id']);
            Gate::authorize('view', $account);
            try {
                $report = $query->execute($request->user(), $account->id, $d['date_from'] ?? null, $d['date_to'] ?? null);
            } catch (AccountingConflict $exception) {
                return response()->view('accounting.ledger', ['accounts' => $this->accounts->ordered($request->user()), 'report' => null,
                    'conflict' => $presentation->generalLedger($exception)], 409);
            }
        }

        return view('accounting.ledger', ['accounts' => $this->accounts->ordered($request->user()), 'report' => $report, 'conflict' => null]);
    }

    public function trial(TrialBalanceRequest $request, TrialBalanceQuery $query, AccountingConflictPresentation $presentation)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        try {
            $report = $query->execute($request->user(), $request->validated()['as_of'] ?? null);
        } catch (AccountingConflict $exception) {
            return response()->view('accounting.trial', ['report' => null, 'conflict' => $presentation->trialBalance($exception)], 409);
        }

        return view('accounting.trial', ['report' => $report, 'conflict' => null]);
    }
}
