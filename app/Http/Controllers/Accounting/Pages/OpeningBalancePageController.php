<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\DeleteOpeningBalanceDraft;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\UpdateOpeningBalanceDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccountingConflictPresentation;
use App\Http\Presenters\OpeningBalanceValidationPresentation;
use App\Http\Requests\Accounting\OpeningBalancePageRequest;
use App\Models\ChartAccount;
use App\Models\OpeningBalanceBatch;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OpeningBalancePageController extends Controller
{
    private function openingBalance(Request $request, string $id): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::ownedBy($request->user())->findOrFail($id);
    }

    private function openingBalanceError(Request $request, string $destination, string $message)
    {
        return redirect($destination)->withInput($request->except('_token'))->withErrors(['accounting' => $message]);
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

    public function openingBalanceStore(OpeningBalancePageRequest $request, CreateOpeningBalanceDraft $action, AccountingConflictPresentation $presentation, OpeningBalanceValidationPresentation $validationPresentation)
    {
        $data = $request->validated();
        try {
            $batch = $action->execute($request->user(), $data['opening_date'], $data['currency'], $data['lines']);
        } catch (ValidationException $exception) {
            return $this->openingBalanceError($request, route('accounting-pages.opening-balances.index'), $validationPresentation->message($exception));
        } catch (AccountingConflict $exception) {
            return $this->openingBalanceError($request, route('accounting-pages.opening-balances.index'), $presentation->openingBalanceDraft($exception));
        }

        return redirect()->route('accounting-pages.opening-balances.show', $batch->id)->with('success', 'تم حفظ مسودة الأرصدة الافتتاحية.');
    }

    public function openingBalanceUpdate(OpeningBalancePageRequest $request, string $openingBalance, UpdateOpeningBalanceDraft $action, AccountingConflictPresentation $presentation, OpeningBalanceValidationPresentation $validationPresentation)
    {
        $batch = $this->openingBalance($request, $openingBalance);
        $destination = route('accounting-pages.opening-balances.show', $batch->id);
        if ($batch->isPosted()) {
            return $this->openingBalanceError($request, $destination, AccountingConflictPresentation::OPENING_BALANCE_POSTED);
        }
        $data = $request->validated();
        try {
            $action->execute($request->user(), $batch->id, $data['opening_date'], $data['currency'], $data['lines']);
        } catch (ValidationException $exception) {
            return $this->openingBalanceError($request, $destination, $validationPresentation->message($exception));
        } catch (AccountingConflict $exception) {
            return $this->openingBalanceError($request, $destination, $presentation->openingBalanceDraft($exception));
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

    public function openingBalancePost(Request $request, string $openingBalance, PostOpeningBalanceBatch $action, AccountingConflictPresentation $presentation)
    {
        $batch = $this->openingBalance($request, $openingBalance);
        $destination = route('accounting-pages.opening-balances.show', $batch->id);
        try {
            $action->execute($request->user(), $batch->id);
        } catch (AccountingConflict $exception) {
            return $this->openingBalanceError($request, $destination, $presentation->openingBalancePost($exception));
        }

        return redirect($destination)->with('success', 'تم ترحيل الأرصدة الافتتاحية وإنشاء القيد المرتبط.');
    }
}
