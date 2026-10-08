<?php

namespace App\Http\Controllers\Accounting\Pages;

use App\Accounting\Actions\CloseFiscalYear;
use App\Accounting\Exceptions\AccountingConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FiscalYearClosePageRequest;
use App\Models\ChartAccount;
use App\Models\FiscalYearClose;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FiscalYearClosePageController extends Controller
{
    public function index(Request $request)
    {
        $closes = FiscalYearClose::ownedBy($request->user())
            ->with([
                'retainedEarningsAccount' => fn ($query) => $query->ownedBy($request->user()),
                'journalEntry' => fn ($query) => $query->ownedBy($request->user()),
            ])
            ->orderBy('start_date')->orderBy('id')->get();

        return view('accounting.fiscal-year-closes.index', compact('closes'));
    }

    public function create(Request $request)
    {
        $accounts = ChartAccount::ownedBy($request->user())->where('type', 'equity')
            ->where('is_active', true)->orderBy('code')->orderBy('id')->get();

        return view('accounting.fiscal-year-closes.create', compact('accounts'));
    }

    public function store(FiscalYearClosePageRequest $request, CloseFiscalYear $action)
    {
        $data = $request->validated();
        try {
            $close = $action->execute($request->user(), $data['start_date'], $data['end_date'],
                $data['currency'], $data['retained_earnings_account_id']);
        } catch (ValidationException $exception) {
            $messages = [];
            foreach (array_keys($exception->errors()) as $field) {
                $messages[$field] = match ($field) {
                    'start_date', 'end_date' => 'أدخل تاريخاً صحيحاً بصيغة YYYY-MM-DD دون مسافات.',
                    'currency' => 'العملة لا تطابق عملة المحاسبة المعتمدة.',
                    'retained_earnings_account_id' => 'اختر حساب أرباح محتجزة صالحاً دون مسافات.',
                    default => 'بيانات إقفال السنة المالية غير صالحة.',
                };
            }
            return redirect()->route('accounting-pages.fiscal-year-closes.create')
                ->withInput($request->only('start_date', 'end_date', 'currency', 'retained_earnings_account_id'))
                ->withErrors($messages);
        } catch (AccountingConflict $exception) {
            return redirect()->route('accounting-pages.fiscal-year-closes.create')
                ->withInput($request->only('start_date', 'end_date', 'currency', 'retained_earnings_account_id'))
                ->withErrors(['accounting' => $this->conflictMessage($exception)]);
        }

        return redirect()->route('accounting-pages.fiscal-year-closes.show', $close->id)
            ->with('success', 'تم إقفال السنة المالية نهائياً بنجاح.');
    }

    public function show(Request $request, string $fiscalYearClose)
    {
        $close = FiscalYearClose::ownedBy($request->user())->findOrFail($fiscalYearClose);
        $close->load([
            'retainedEarningsAccount' => fn ($query) => $query->ownedBy($request->user()),
            'journalEntry' => fn ($query) => $query->ownedBy($request->user()),
        ]);

        return view('accounting.fiscal-year-closes.show', compact('close'));
    }

    private function conflictMessage(AccountingConflict $exception): string
    {
        return match ($exception->getMessage()) {
            'Fiscal year overlaps an existing close.' => 'تتداخل الفترة المختارة مع سنة مالية مقفلة مسبقاً.',
            'Draft journals must be resolved before closing the fiscal year.' => 'يجب معالجة مسودات القيود اليومية ضمن الفترة قبل الإقفال.',
            'Draft opening balances must be resolved before closing the fiscal year.' => 'يجب معالجة مسودات الأرصدة الافتتاحية ضمن الفترة قبل الإقفال.',
            'Retained earnings account must be an owned, active equity account.' => 'اختر حساب أرباح محتجزة نشطاً من حقوق الملكية يخصك.',
            'Posted journal currency does not match the fiscal-year currency.' => 'عملة أحد القيود المرحلة لا تطابق عملة السنة المالية.',
            default => 'تعذر إقفال السنة المالية. تحقق من القيود والحسابات وتوازن المبالغ.',
        };
    }
}
