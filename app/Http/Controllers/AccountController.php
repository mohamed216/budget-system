<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function destroy(Account $account)
    {
        Gate::authorize('delete', $account);
        DB::transaction(function () use ($account) {
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::authorize('delete', $locked);
            if ($locked->transactions()->exists()) {
                throw ValidationException::withMessages(['account' => 'An account with transactions cannot be deleted.']);
            }
            $locked->delete();
        });

        return redirect()->route('accounts.index')->with('success', 'Account deleted');
    }
}
