<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\DashboardController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::resource('accounts', AccountController::class);
Route::resource('categories', CategoryController::class);
Route::resource('transactions', TransactionController::class);
Route::resource('budgets', BudgetController::class);
