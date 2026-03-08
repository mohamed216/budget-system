<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $transactions = Transaction::with(['account', 'category'])
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->when($request->month, fn($q, $m) => $q->whereMonth('date', $m))
            ->orderByDesc('date')
            ->get();

        $accounts = Account::all();
        $categories = Category::all();

        return view('transactions.index', compact('transactions', 'accounts', 'categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'account_id' => 'required',
            'category_id' => 'required',
            'amount' => 'required|numeric',
            'type' => 'required|in:income,expense',
            'date' => 'required|date'
        ]);

        Transaction::create($request->all());

        // Update account balance
        $account = Account::find($request->account_id);
        if ($request->type === 'income') {
            $account->balance += $request->amount;
        } else {
            $account->balance -= $request->amount;
        }
        $account->save();

        return redirect()->route('transactions.index')->with('success', 'Transaction added');
    }
}
