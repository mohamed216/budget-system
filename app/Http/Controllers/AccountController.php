<?php

namespace App\Http\Controllers;

use App\Actions\DeleteAccount;
use App\Actions\Exceptions\AccountHasTransactions;
use App\Http\Requests\StoreAccountRequest;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = Account::ownedBy($request->user())->get();

        return view('accounts.index', compact('accounts'));
    }

    public function store(StoreAccountRequest $request)
    {
        $request->user()->accounts()->create($request->validated());

        return redirect()->route('accounts.index')->with('success', 'Account created');
    }

    public function destroy(Request $request, Account $account, DeleteAccount $deleteAccount)
    {
        try {
            $deleteAccount->execute($request->user(), $account);
        } catch (AccountHasTransactions) {
            throw ValidationException::withMessages(['account' => 'An account with transactions cannot be deleted.']);
        }

        return redirect()->route('accounts.index')->with('success', 'Account deleted');
    }
}
