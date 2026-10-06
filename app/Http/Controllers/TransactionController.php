<?php

namespace App\Http\Controllers;

use App\Actions\PostTransaction;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\TransactionIndexRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;

class TransactionController extends Controller
{
    public function index(TransactionIndexRequest $request)
    {
        $filters = $request->validated();
        $transactions = Transaction::ownedBy($request->user())->with(['account', 'category'])
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['month'] ?? null, fn ($q, $m) => $q->whereMonth('date', $m))
            ->when($filters['year'] ?? null, fn ($q, $y) => $q->whereYear('date', $y))
            ->orderByDesc('date')
            ->get();

        $accounts = Account::ownedBy($request->user())->get();
        $categories = Category::ownedBy($request->user())->get();

        return view('transactions.index', compact('transactions', 'accounts', 'categories'));
    }

    public function store(StoreTransactionRequest $request, PostTransaction $postTransaction)
    {
        $postTransaction->handle($request->user(), $request->validated());

        return redirect()->route('transactions.index')->with('success', 'Transaction added');
    }
}
