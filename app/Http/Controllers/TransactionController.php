<?php

namespace App\Http\Controllers;

use App\Actions\PostTransaction;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\TransactionIndexRequest;
use App\Models\Account;
use App\Models\Category;
use App\Queries\LegacyPageRelations;
use App\Queries\LegacyRelationIntegrity;

class TransactionController extends Controller
{
    public function index(TransactionIndexRequest $request, LegacyPageRelations $relations)
    {
        $filters = $request->validated();
        try {
            $transactions = $relations->transactions($request->user(), $filters);
        } catch (LegacyRelationIntegrity) {
            return response()->view('errors.legacy-relation-integrity', [], 409);
        }

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
