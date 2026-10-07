<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\DeleteAccountingPeriod;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\DeleteOpeningBalanceDraft;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\ReopenAccountingPeriod;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Actions\UpdateAccountingPeriod;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Actions\UpdateOpeningBalanceDraft;
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
use App\Http\Requests\Accounting\OpeningBalancePageRequest;
use App\Http\Requests\Accounting\StatementOfFinancialPositionPageRequest;
use App\Http\Requests\Accounting\TrialBalanceRequest;
use App\Models\AccountingPeriod;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingPageController extends Controller
{
    private function openingBalance(Request $request, string $id): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::ownedBy($request->user())->findOrFail($id);
    }

    private function openingBalanceError(Request $request, string $destination, string $message)
    {
        return redirect($destination)->withInput($request->except('_token'))->withErrors(['accounting' => $message]);
    }

    private function openingBalanceValidationMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        if (isset($errors['currency'])) {
            return 'العملة لا تطابق عملة المحاسبة المعتمدة.';
        }
        foreach (array_keys($errors) as $field) {
            if (preg_match('/^lines\.\d+\.chart_account_id$/D', $field)) {
                return 'الحساب مكرر داخل الدفعة.';
            }
        }
        if (str_contains(implode(' ', $errors['lines'] ?? []), 'accounts must')) {
            return 'لا يمكن استخدام حساب غير نشط أو غير متاح.';
        }

        return 'الأرصدة الافتتاحية غير متوازنة أو غير صالحة.';
    }

    public function openingBalanceIndex(Request $request)
    {
        return view('accounting.opening-balances', [
            'batches' => OpeningBalanceBatch::ownedBy($request->user())->orderByDesc('opening_date')->orderByDesc('id')->get(),
            'accounts' => ChartAccount::ownedBy($request->user())->where('is_active', true)->orderBy('code')->orderBy('id')->get(),
        ]);
    }

    public function openingBalanceShow(Request $request, string $openingBalance)
    {
        $batch = $this->openingBalance($request, $openingBalance);
        $batch->load([
            'lines' => fn ($query) => $query->ownedBy($request->user()),
            'lines.chartAccount' => fn ($query) => $query->ownedBy($request->user()),
            'journalEntry' => fn ($query) => $query->ownedBy($request->user()),
        ]);

        return view('accounting.opening-balance', [
            'batch' => $batch,
            'accounts' => ChartAccount::ownedBy($request->user())->orderBy('code')->orderBy('id')->get(),
        ]);
    }

    public function openingBalanceStore(OpeningBalancePageRequest $request, CreateOpeningBalanceDraft $action)
    {
        $data = $request->validated();
        try {
            $batch = $action->execute($request->user(), $data['opening_date'], $data['currency'], $data['lines']);
        } catch (ValidationException $exception) {
            return $this->openingBalanceError($request, route('accounting-pages.opening-balances.index'), $this->openingBalanceValidationMessage($exception));
        } catch (AccountingConflict $exception) {
            return $this->openingBalanceError($request, route('accounting-pages.opening-balances.index'), 'تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.');
        }

        return redirect()->route('accounting-pages.opening-balances.show', $batch->id)->with('success', 'تم حفظ مسودة الأرصدة الافتتاحية.');
    }

    public function openingBalanceUpdate(OpeningBalancePageRequest $request, string $openingBalance, UpdateOpeningBalanceDraft $action)
    {
        $batch = $this->openingBalance($request, $openingBalance);
        $destination = route('accounting-pages.opening-balances.show', $batch->id);
        if ($batch->isPosted()) {
            return $this->openingBalanceError($request, $destination, 'لا يمكن تعديل أرصدة افتتاحية تم ترحيلها.');
        }
        $data = $request->validated();
        try {
            $action->execute($request->user(), $batch->id, $data['opening_date'], $data['currency'], $data['lines']);
        } catch (ValidationException $exception) {
            return $this->openingBalanceError($request, $destination, $this->openingBalanceValidationMessage($exception));
        } catch (AccountingConflict $exception) {
            $message = str_contains($exception->getMessage(), 'period')
                ? 'تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.' : 'لا يمكن تعديل أرصدة افتتاحية تم ترحيلها.';

            return $this->openingBalanceError($request, $destination, $message);
        }

        return redirect($destination)->with('success', 'تم تحديث مسودة الأرصدة الافتتاحية.');
    }

    public function openingBalanceDelete(Request $request, string $openingBalance, DeleteOpeningBalanceDraft $action)
    {
        $batch = $this->openingBalance($request, $openingBalance);
        $destination = route('accounting-pages.opening-balances.show', $batch->id);
        try {
            $action->execute($request->user(), $batch->id);
        } catch (AccountingConflict $exception) {
            return $this->openingBalanceError($request, $destination, 'لا يمكن حذف أرصدة افتتاحية تم ترحيلها.');
        }

        return redirect()->route('accounting-pages.opening-balances.index')->with('success', 'تم حذف مسودة الأرصدة الافتتاحية.');
    }

    public function openingBalancePost(Request $request, string $openingBalance, PostOpeningBalanceBatch $action)
    {
        $batch = $this->openingBalance($request, $openingBalance);
        $destination = route('accounting-pages.opening-balances.show', $batch->id);
        try {
            $action->execute($request->user(), $batch->id);
        } catch (AccountingConflict $exception) {
            $message = str_contains($exception->getMessage(), 'period')
                ? 'تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.'
                : 'تعذر ترحيل الأرصدة الافتتاحية. تحقق من العملة والحسابات النشطة وتوازن السطور.';

            return $this->openingBalanceError($request, $destination, $message);
        }

        return redirect($destination)->with('success', 'تم ترحيل الأرصدة الافتتاحية وإنشاء القيد المرتبط.');
    }

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
