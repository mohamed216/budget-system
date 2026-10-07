<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\DeleteAccountingPeriod;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReopenAccountingPeriod;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Actions\UpdateAccountingPeriod;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\GeneralLedgerQuery;
use App\Accounting\Queries\IncomeStatementQuery;
use App\Accounting\Queries\StatementOfFinancialPositionQuery;
use App\Accounting\Queries\TrialBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountingPeriodRequest;
use App\Http\Requests\Accounting\ChartAccountRequest;
use App\Http\Requests\Accounting\GeneralLedgerPageRequest;
use App\Http\Requests\Accounting\IncomeStatementPageRequest;
use App\Http\Requests\Accounting\JournalPageRequest;
use App\Http\Requests\Accounting\StatementOfFinancialPositionPageRequest;
use App\Http\Requests\Accounting\TrialBalanceRequest;
use App\Models\AccountingPeriod;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountingPageController extends Controller
{
    private function accounts(Request $request)
    {
        return ChartAccount::ownedBy($request->user())->orderBy('code')->orderBy('id')->get();
    }

    private function chart(Request $request, string $id, string $ability): ChartAccount
    {
        $account = ChartAccount::ownedBy($request->user())->findOrFail($id);
        Gate::authorize($ability, $account);

        return $account;
    }

    private function journal(Request $request, string $id, string $ability): JournalEntry
    {
        $entry = JournalEntry::ownedBy($request->user())->findOrFail($id);
        Gate::authorize($ability, $entry);

        return $entry;
    }

    private function period(Request $request, string $id): AccountingPeriod
    {
        $period = AccountingPeriod::ownedBy($request->user())->findOrFail($id);
        Gate::authorize('view', $period);

        return $period;
    }

    private function mutatePeriod(Request $request, string $id, string $ability, callable $operation)
    {
        $period = $this->period($request, $id);

        return $this->mutate($request, function () use ($ability, $period, $operation): void {
            if (Gate::inspect($ability, $period)->denied()) {
                throw new AccountingConflict('لا يمكن تنفيذ العملية على هذه الفترة المحاسبية في حالتها الحالية.');
            }
            $operation($period);
        }, route('accounting-pages.periods.index'));
    }

    private function mutate(Request $request, callable $operation, string $destination)
    {
        try {
            $operation();
        } catch (AccountingConflict $exception) {
            return back()->withInput($request->except('_token'))->withErrors(['accounting' => $exception->getMessage()]);
        }

        return redirect($destination)->with('success', 'تم حفظ العملية بنجاح.');
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

        return $this->mutate($request, fn () => $action->execute($request->user(), $dates['start_date'], $dates['end_date']), route('accounting-pages.periods.index'));
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

    public function chartIndex(Request $request)
    {
        Gate::authorize('viewAny', ChartAccount::class);

        return view('accounting.chart', ['accounts' => $this->accounts($request), 'editing' => null]);
    }

    public function chartEdit(Request $request, string $chartAccount)
    {
        return view('accounting.chart', ['accounts' => $this->accounts($request), 'editing' => $this->chart($request, $chartAccount, 'update')]);
    }

    public function chartStore(ChartAccountRequest $request, CreateChartAccount $action)
    {
        Gate::authorize('create', ChartAccount::class);
        $d = $request->validated();

        return $this->mutate($request, fn () => $action->execute($request->user(), $d['code'], $d['name'], $d['type'], (bool) $d['is_active'], isset($d['parent_id']) ? (int) $d['parent_id'] : null), route('accounting-pages.chart.index'));
    }

    public function chartUpdate(ChartAccountRequest $request, string $chartAccount, UpdateChartAccount $action)
    {
        $account = $this->chart($request, $chartAccount, 'update');
        $d = $request->validated();

        return $this->mutate($request, fn () => $action->execute($request->user(), $account->id, $d['code'], $d['name'], $d['type'], (bool) $d['is_active'], isset($d['parent_id']) ? (int) $d['parent_id'] : null), route('accounting-pages.chart.index'));
    }

    public function chartDelete(Request $request, string $chartAccount, DeleteChartAccount $action)
    {
        $account = $this->chart($request, $chartAccount, 'delete');

        return $this->mutate($request, fn () => $action->execute($request->user(), $account->id), route('accounting-pages.chart.index'));
    }

    public function journalIndex(Request $request)
    {
        Gate::authorize('viewAny', JournalEntry::class);

        return view('accounting.journals', ['journals' => JournalEntry::ownedBy($request->user())
            ->with(['reversal' => fn ($query) => $query->ownedBy($request->user())])
            ->orderByDesc('entry_date')->orderByDesc('id')->get()]);
    }

    public function journalCreate(Request $request)
    {
        Gate::authorize('create', JournalEntry::class);

        return view('accounting.draft', ['journal' => null, 'accounts' => $this->accounts($request)]);
    }

    public function journalEdit(Request $request, string $journal)
    {
        $entry = $this->journal($request, $journal, 'update');

        return view('accounting.draft', ['journal' => $entry->load('lines'), 'accounts' => $this->accounts($request)]);
    }

    public function journalShow(Request $request, string $journal)
    {
        $entry = $this->journal($request, $journal, 'view');
        $entry->load([
            'lines' => fn ($q) => $q->ownedBy($request->user()),
            'lines.chartAccount' => fn ($q) => $q->ownedBy($request->user()),
            'reversal' => fn ($q) => $q->ownedBy($request->user()),
            'reversalOf' => fn ($q) => $q->ownedBy($request->user()),
        ]);

        return view('accounting.journal', ['journal' => $entry, 'canReverse' => Gate::allows('reverse', $entry)]);
    }

    public function journalStore(JournalPageRequest $request, SaveJournalDraft $action)
    {
        Gate::authorize('create', JournalEntry::class);

        return $this->save($request, $action);
    }

    public function journalUpdate(JournalPageRequest $request, string $journal, SaveJournalDraft $action)
    {
        return $this->save($request, $action, $this->journal($request, $journal, 'update'));
    }

    private function save(JournalPageRequest $request, SaveJournalDraft $action, ?JournalEntry $entry = null)
    {
        $d = $request->validated();
        try {
            $saved = $action->execute($request->user(), $d['entry_date'], $d['currency'], $d['lines'], $d['reference'] ?? null, $d['description'] ?? null, $entry?->id, $entry ? (int) $d['version'] : null);
        } catch (AccountingConflict $exception) {
            return back()->withInput($request->except('_token'))->withErrors(['accounting' => $exception->getMessage()]);
        }

        return redirect()->route('accounting-pages.journals.show', $saved->id)->with('success', 'تم حفظ المسودة.');
    }

    public function journalDelete(Request $request, string $journal, DeleteJournalDraft $action)
    {
        $entry = $this->journal($request, $journal, 'delete');

        return $this->mutate($request, fn () => $action->execute($request->user(), $entry->id), route('accounting-pages.journals.index'));
    }

    public function journalPost(Request $request, string $journal, PostJournalEntry $action)
    {
        $entry = $this->journal($request, $journal, 'view');
        Gate::authorize($entry->isPosted() ? 'view' : 'post', $entry);

        return $this->mutate($request, fn () => $action->execute($request->user(), $entry->id), route('accounting-pages.journals.show', $entry->id));
    }

    public function journalReverse(Request $request, string $journal, ReverseJournalEntry $action)
    {
        $entry = $this->journal($request, $journal, 'view');
        try {
            if (Gate::inspect('reverse', $entry)->denied()) {
                throw new AccountingConflict('لا يمكن عكس هذا القيد؛ يجب أن يكون قيداً أصلياً مرحلاً ولم يُعكس من قبل.');
            }
            $reversal = $action->execute($request->user(), $entry->id);
        } catch (AccountingConflict $exception) {
            return back()->withInput($request->except('_token'))->withErrors(['accounting' => $exception->getMessage()]);
        }

        return redirect()->route('accounting-pages.journals.show', $reversal->id)
            ->with('success', 'تم إنشاء القيد العكسي وترحيله بنجاح.');
    }

    public function ledger(GeneralLedgerPageRequest $request, GeneralLedgerQuery $query)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        $report = null;
        if ($request->hasAny(['chart_account_id', 'date_from', 'date_to'])) {
            $d = $request->validated();
            $account = $this->chart($request, (string) $d['chart_account_id'], 'view');
            $report = $query->execute($request->user(), $account->id, $d['date_from'] ?? null, $d['date_to'] ?? null);
        }

        return view('accounting.ledger', ['accounts' => $this->accounts($request), 'report' => $report]);
    }

    public function trial(TrialBalanceRequest $request, TrialBalanceQuery $query)
    {
        Gate::authorize('viewAny', ChartAccount::class);
        try {
            $report = $query->execute($request->user(), $request->validated()['as_of'] ?? null);
        } catch (AccountingConflict $exception) {
            return response()->view('accounting.trial', ['report' => null, 'conflict' => $exception->getMessage()], 409);
        }

        return view('accounting.trial', ['report' => $report, 'conflict' => null]);
    }

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
