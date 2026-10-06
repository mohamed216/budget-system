<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $totalBalance = Account::ownedBy($request->user())->sum('balance');
        $totalIncome = Transaction::ownedBy($request->user())->where('type', 'income')->sum('amount');
        $totalExpense = Transaction::ownedBy($request->user())->where('type', 'expense')->sum('amount');

        $recentTransactions = Transaction::ownedBy($request->user())->with(['account', 'category'])
            ->orderByDesc('date')
            ->take(10)
            ->get();

        $incomeByCategory = Transaction::ownedBy($request->user())->where('type', 'income')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get();

        $expenseByCategory = Transaction::ownedBy($request->user())->where('type', 'expense')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get();

        return view('dashboard', compact(
            'totalBalance', 'totalIncome', 'totalExpense',
            'recentTransactions', 'incomeByCategory', 'expenseByCategory'
        ));
    }
}
