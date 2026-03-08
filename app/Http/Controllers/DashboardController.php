<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Budget;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $totalBalance = Account::sum('balance');
        $totalIncome = Transaction::where('type', 'income')->sum('amount');
        $totalExpense = Transaction::where('type', 'expense')->sum('amount');
        
        $recentTransactions = Transaction::with(['account', 'category'])
            ->orderByDesc('date')
            ->take(10)
            ->get();

        $incomeByCategory = Transaction::where('type', 'income')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get();

        $expenseByCategory = Transaction::where('type', 'expense')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get();

        return view('dashboard', compact(
            'totalBalance', 'totalIncome', 'totalExpense', 
            'recentTransactions', 'incomeByCategory', 'expenseByCategory'
        ));
    }
}
