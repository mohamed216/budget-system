<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Queries\OwnedChartAccounts;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingPageMutation;
use App\Http\Requests\Accounting\ChartAccountRequest;
use App\Models\ChartAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ChartAccountPageController extends Controller
{
    public function __construct(
        private readonly OwnedChartAccounts $accounts,
        private readonly AccountingPageMutation $mutation,
    ) {}

    private function chart(Request $request, string $id, string $ability): ChartAccount
    {
        $account = $this->accounts->find($request->user(), $id);
        Gate::authorize($ability, $account);

        return $account;
    }

    public function chartIndex(Request $request)
    {
        Gate::authorize('viewAny', ChartAccount::class);

        return view('accounting.chart', ['accounts' => $this->accounts->ordered($request->user()), 'editing' => null]);
    }

    public function chartEdit(Request $request, string $chartAccount)
    {
        return view('accounting.chart', ['accounts' => $this->accounts->ordered($request->user()), 'editing' => $this->chart($request, $chartAccount, 'update')]);
    }

    public function chartStore(ChartAccountRequest $request, CreateChartAccount $action)
    {
        Gate::authorize('create', ChartAccount::class);
        $d = $request->validated();

        return $this->mutation->handle($request, fn () => $action->execute($request->user(), $d['code'], $d['name'], $d['type'], (bool) $d['is_active'], isset($d['parent_id']) ? (int) $d['parent_id'] : null), route('accounting-pages.chart.index'));
    }

    public function chartUpdate(ChartAccountRequest $request, string $chartAccount, UpdateChartAccount $action)
    {
        $account = $this->chart($request, $chartAccount, 'update');
        $d = $request->validated();

        return $this->mutation->handle($request, fn () => $action->execute($request->user(), $account->id, $d['code'], $d['name'], $d['type'], (bool) $d['is_active'], isset($d['parent_id']) ? (int) $d['parent_id'] : null), route('accounting-pages.chart.index'));
    }

    public function chartDelete(Request $request, string $chartAccount, DeleteChartAccount $action)
    {
        $account = $this->chart($request, $chartAccount, 'delete');

        return $this->mutation->handle($request, fn () => $action->execute($request->user(), $account->id), route('accounting-pages.chart.index'));
    }
}
