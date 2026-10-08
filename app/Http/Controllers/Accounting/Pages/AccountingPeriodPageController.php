<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\DeleteAccountingPeriod;
use App\Accounting\Actions\ReopenAccountingPeriod;
use App\Accounting\Actions\UpdateAccountingPeriod;
use App\Accounting\Exceptions\AccountingConflict;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingPageMutation;
use App\Http\Requests\Accounting\AccountingPeriodRequest;
use App\Models\AccountingPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountingPeriodPageController extends Controller
{
    public function __construct(private readonly AccountingPageMutation $mutation) {}

    private function period(Request $request, string $id): AccountingPeriod
    {
        $period = AccountingPeriod::ownedBy($request->user())->findOrFail($id);
        Gate::authorize('view', $period);

        return $period;
    }

    private function mutatePeriod(Request $request, string $id, string $ability, callable $operation)
    {
        $period = $this->period($request, $id);

        return $this->mutation->handle($request, function () use ($ability, $period, $operation): void {
            if (Gate::inspect($ability, $period)->denied()) {
                throw new AccountingConflict('لا يمكن تنفيذ العملية على هذه الفترة المحاسبية في حالتها الحالية.');
            }
            $operation($period);
        }, route('accounting-pages.periods.index'));
    }

    public function periodIndex(Request $request)
    {
        Gate::authorize('viewAny', AccountingPeriod::class);

        return view('accounting.periods', ['periods' => AccountingPeriod::ownedBy($request->user())
            ->orderBy('start_date')->orderBy('id')->get()]);
    }

    public function periodStore(AccountingPeriodRequest $request, CreateAccountingPeriod $action)
    {
        Gate::authorize('create', AccountingPeriod::class);
        $dates = $request->validated();

        return $this->mutation->handle($request, fn () => $action->execute($request->user(), $dates['start_date'], $dates['end_date']), route('accounting-pages.periods.index'));
    }

    public function periodUpdate(AccountingPeriodRequest $request, string $period, UpdateAccountingPeriod $action)
    {
        $dates = $request->validated();

        return $this->mutatePeriod($request, $period, 'update', fn (AccountingPeriod $record) => $action->execute($request->user(), $record->id, $dates['start_date'], $dates['end_date']));
    }

    public function periodClose(Request $request, string $period, CloseAccountingPeriod $action)
    {
        return $this->mutatePeriod($request, $period, 'close', fn (AccountingPeriod $record) => $action->execute($request->user(), $record->id));
    }

    public function periodReopen(Request $request, string $period, ReopenAccountingPeriod $action)
    {
        return $this->mutatePeriod($request, $period, 'reopen', fn (AccountingPeriod $record) => $action->execute($request->user(), $record->id));
    }

    public function periodDelete(Request $request, string $period, DeleteAccountingPeriod $action)
    {
        return $this->mutatePeriod($request, $period, 'delete', fn (AccountingPeriod $record) => $action->execute($request->user(), $record->id));
    }
}
