<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\OwnedChartAccounts;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingPageMutation;
use App\Http\Requests\Accounting\ChartAccountRequest;
use App\Http\Requests\Accounting\JournalPageRequest;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountingPageController extends Controller
{
    public function __construct(
        private readonly OwnedChartAccounts $accounts,
        private readonly AccountingPageMutation $mutation,
    ) {}

    private function accounts(Request $request)
    {
        return $this->accounts->ordered($request->user());
    }

    private function chart(Request $request, string $id, string $ability): ChartAccount
    {
        $account = $this->accounts->find($request->user(), $id);
        Gate::authorize($ability, $account);

        return $account;
    }

    private function journal(Request $request, string $id, string $ability): JournalEntry
    {
        $entry = JournalEntry::ownedBy($request->user())->findOrFail($id);
        Gate::authorize($ability, $entry);

        return $entry;
    }

    private function mutate(Request $request, callable $operation, string $destination)
    {
        return $this->mutation->handle($request, $operation, $destination);
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
}
