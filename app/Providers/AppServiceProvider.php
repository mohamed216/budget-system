<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Policies\FinancialRecordPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Account::class, Category::class, Transaction::class, Budget::class] as $model) {
            Gate::policy($model, FinancialRecordPolicy::class);
        }
    }
}
