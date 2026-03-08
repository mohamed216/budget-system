<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;

class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        // Accounts
        Account::create(['name' => 'البنك الأهلي', 'type' => 'bank', 'balance' => 50000]);
        Account::create(['name' => 'الصندوق', 'type' => 'cash', 'balance' => 10000]);

        // Categories - Income
        Category::create(['name' => 'الراتب', 'type' => 'income']);
        Category::create(['name' => 'مبيعات', 'type' => 'income']);
        Category::create(['name' => 'استثمارات', 'type' => 'income']);

        // Categories - Expense
        Category::create(['name' => 'الإيجار', 'type' => 'expense']);
        Category::create(['name' => 'الطعام', 'type' => 'expense']);
        Category::create(['name' => 'المواصلات', 'type' => 'expense']);
        Category::create(['name' => 'الفواتير', 'type' => 'expense']);
    }
}
