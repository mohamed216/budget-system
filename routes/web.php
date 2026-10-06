<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Accounting\ChartAccountController;
use App\Http\Controllers\Accounting\GeneralLedgerController;
use App\Http\Controllers\Accounting\JournalEntryController;
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
    Route::get('general-ledger', GeneralLedgerController::class)->name('general-ledger');
    Route::get('trial-balance', TrialBalanceController::class)->name('trial-balance');
});
