<?php

namespace App\Actions;

use App\Actions\Exceptions\AccountHasTransactions;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteAccount
{
    public function execute(User $user, Account $account): void
    {
        Gate::forUser($user)->authorize('delete', $account);

        DB::transaction(function () use ($user, $account): void {
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
            Gate::forUser($user)->authorize('delete', $locked);
            if ($locked->transactions()->exists()) {
                throw new AccountHasTransactions;
            }
            $locked->delete();
        });
    }
}
