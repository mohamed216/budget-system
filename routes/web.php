<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Accounting\AccountingPeriodController;
use App\Http\Controllers\Accounting\ChartAccountController;
use App\Http\Controllers\Accounting\FiscalYearCloseController;
use App\Http\Controllers\Accounting\Pages\AccountingPeriodPageController;
use App\Http\Controllers\Accounting\Pages\ChartAccountPageController;
use App\Http\Controllers\Accounting\Pages\FinancialStatementPageController;
use App\Http\Controllers\Accounting\Pages\FiscalYearClosePageController;
use App\Http\Controllers\Accounting\Pages\LedgerReportPageController;
use App\Http\Controllers\Accounting\Pages\OpeningBalancePageController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\IncomeStatementController;
use App\Http\Controllers\Accounting\JournalEntryController;
use App\Http\Controllers\Accounting\OpeningBalanceController;
use App\Http\Controllers\Accounting\StatementOfFinancialPositionController;
use App\Http\Controllers\Accounting\TrialBalanceController;
use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('accounts', AccountController::class)->only(['index', 'store', 'destroy']);
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'destroy']);
    Route::resource('transactions', TransactionController::class)->only(['index', 'store']);
    Route::resource('budgets', BudgetController::class)->only(['index', 'store', 'destroy']);
});

Route::middleware('auth')->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('fiscal-year-closes', [FiscalYearCloseController::class, 'index'])->name('fiscal-year-closes.index');
    Route::post('fiscal-year-closes', [FiscalYearCloseController::class, 'store'])->name('fiscal-year-closes.store');
    Route::get('fiscal-year-closes/{fiscalYearClose}', [FiscalYearCloseController::class, 'show'])->whereNumber('fiscalYearClose')->name('fiscal-year-closes.show');
    Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
    Route::get('opening-balances/{openingBalance}', [OpeningBalanceController::class, 'show'])->whereNumber('openingBalance')->name('opening-balances.show');
    Route::put('opening-balances/{openingBalance}', [OpeningBalanceController::class, 'update'])->whereNumber('openingBalance')->name('opening-balances.update');
    Route::delete('opening-balances/{openingBalance}', [OpeningBalanceController::class, 'destroy'])->whereNumber('openingBalance')->name('opening-balances.destroy');
    Route::post('opening-balances/{openingBalance}/post', [OpeningBalanceController::class, 'post'])->whereNumber('openingBalance')->name('opening-balances.post');
    Route::get('periods', [AccountingPeriodController::class, 'index'])->name('periods.index');
    Route::post('periods', [AccountingPeriodController::class, 'store'])->name('periods.store');
    Route::get('periods/{period}', [AccountingPeriodController::class, 'show'])->whereNumber('period')->name('periods.show');
    Route::put('periods/{period}', [AccountingPeriodController::class, 'update'])->whereNumber('period')->name('periods.update');
    Route::post('periods/{period}/close', [AccountingPeriodController::class, 'close'])->whereNumber('period')->name('periods.close');
    Route::post('periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->whereNumber('period')->name('periods.reopen');
    Route::delete('periods/{period}', [AccountingPeriodController::class, 'destroy'])->whereNumber('period')->name('periods.destroy');
    Route::get('chart-accounts', [ChartAccountController::class, 'index'])->name('chart-accounts.index');
    Route::post('chart-accounts', [ChartAccountController::class, 'store'])->name('chart-accounts.store');
    Route::put('chart-accounts/{chartAccount}', [ChartAccountController::class, 'update'])->whereNumber('chartAccount')->name('chart-accounts.update');
    Route::delete('chart-accounts/{chartAccount}', [ChartAccountController::class, 'destroy'])->whereNumber('chartAccount')->name('chart-accounts.destroy');
    Route::get('journals', [JournalEntryController::class, 'index'])->name('journals.index');
    Route::get('journals/{journal}', [JournalEntryController::class, 'show'])->whereNumber('journal')->name('journals.show');
    Route::post('journals', [JournalEntryController::class, 'store'])->name('journals.store');
    Route::put('journals/{journal}', [JournalEntryController::class, 'update'])->whereNumber('journal')->name('journals.update');
    Route::delete('journals/{journal}', [JournalEntryController::class, 'destroy'])->whereNumber('journal')->name('journals.destroy');
    Route::post('journals/{journal}/post', [JournalEntryController::class, 'post'])->whereNumber('journal')->name('journals.post');
    Route::post('journals/{journal}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('journal')->name('journals.reverse');
    Route::get('general-ledger', GeneralLedgerController::class)->name('general-ledger');
    Route::get('trial-balance', TrialBalanceController::class)->name('trial-balance');
    Route::get('income-statement', IncomeStatementController::class)->name('income-statement');
    Route::get('balance-sheet', StatementOfFinancialPositionController::class)->name('balance-sheet');
});

Route::middleware('auth')->prefix('accounting/pages')->name('accounting-pages.')->controller(\App\Http\Controllers\Accounting\Pages\AccountingPageController::class)->group(function () {
    Route::get('fiscal-year-closes', [FiscalYearClosePageController::class, 'index'])->name('fiscal-year-closes.index');
    Route::get('fiscal-year-closes/create', [FiscalYearClosePageController::class, 'create'])->name('fiscal-year-closes.create');
    Route::post('fiscal-year-closes', [FiscalYearClosePageController::class, 'store'])->name('fiscal-year-closes.store');
    Route::get('fiscal-year-closes/{fiscalYearClose}', [FiscalYearClosePageController::class, 'show'])->whereNumber('fiscalYearClose')->name('fiscal-year-closes.show');
    Route::get('opening-balances', [OpeningBalancePageController::class, 'openingBalanceIndex'])->name('opening-balances.index');
    Route::post('opening-balances', [OpeningBalancePageController::class, 'openingBalanceStore'])->name('opening-balances.store');
    Route::get('opening-balances/{openingBalance}', [OpeningBalancePageController::class, 'openingBalanceShow'])->whereNumber('openingBalance')->name('opening-balances.show');
    Route::put('opening-balances/{openingBalance}', [OpeningBalancePageController::class, 'openingBalanceUpdate'])->whereNumber('openingBalance')->name('opening-balances.update');
    Route::delete('opening-balances/{openingBalance}', [OpeningBalancePageController::class, 'openingBalanceDelete'])->whereNumber('openingBalance')->name('opening-balances.destroy');
    Route::post('opening-balances/{openingBalance}/post', [OpeningBalancePageController::class, 'openingBalancePost'])->whereNumber('openingBalance')->name('opening-balances.post');
    Route::get('periods', [AccountingPeriodPageController::class, 'periodIndex'])->name('periods.index');
    Route::post('periods', [AccountingPeriodPageController::class, 'periodStore'])->name('periods.store');
    Route::put('periods/{period}', [AccountingPeriodPageController::class, 'periodUpdate'])->whereNumber('period')->name('periods.update');
    Route::post('periods/{period}/close', [AccountingPeriodPageController::class, 'periodClose'])->whereNumber('period')->name('periods.close');
    Route::post('periods/{period}/reopen', [AccountingPeriodPageController::class, 'periodReopen'])->whereNumber('period')->name('periods.reopen');
    Route::delete('periods/{period}', [AccountingPeriodPageController::class, 'periodDelete'])->whereNumber('period')->name('periods.destroy');
    Route::get('chart-accounts', [ChartAccountPageController::class, 'chartIndex'])->name('chart.index');
    Route::get('chart-accounts/{chartAccount}/edit', [ChartAccountPageController::class, 'chartEdit'])->whereNumber('chartAccount')->name('chart.edit');
    Route::post('chart-accounts', [ChartAccountPageController::class, 'chartStore'])->name('chart.store');
    Route::put('chart-accounts/{chartAccount}', [ChartAccountPageController::class, 'chartUpdate'])->whereNumber('chartAccount')->name('chart.update');
    Route::delete('chart-accounts/{chartAccount}', [ChartAccountPageController::class, 'chartDelete'])->whereNumber('chartAccount')->name('chart.destroy');
    Route::get('journals', 'journalIndex')->name('journals.index');
    Route::get('journals/create', 'journalCreate')->name('journals.create');
    Route::get('journals/{journal}/edit', 'journalEdit')->whereNumber('journal')->name('journals.edit');
    Route::get('journals/{journal}', 'journalShow')->whereNumber('journal')->name('journals.show');
    Route::post('journals', 'journalStore')->name('journals.store');
    Route::put('journals/{journal}', 'journalUpdate')->whereNumber('journal')->name('journals.update');
    Route::delete('journals/{journal}', 'journalDelete')->whereNumber('journal')->name('journals.destroy');
    Route::post('journals/{journal}/post', 'journalPost')->whereNumber('journal')->name('journals.post');
    Route::post('journals/{journal}/reverse', 'journalReverse')->whereNumber('journal')->name('journals.reverse');
    Route::get('general-ledger', [LedgerReportPageController::class, 'ledger'])->name('ledger');
    Route::get('trial-balance', [LedgerReportPageController::class, 'trial'])->name('trial');
    Route::get('income-statement', [FinancialStatementPageController::class, 'incomeStatement'])->name('income-statement');
    Route::get('balance-sheet', [FinancialStatementPageController::class, 'balanceSheet'])->name('balance-sheet');
});
