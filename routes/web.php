<?php

use App\Http\Controllers\AccountController;
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
