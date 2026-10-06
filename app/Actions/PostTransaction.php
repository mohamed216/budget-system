<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PostTransaction
{
    /** @param array<string, mixed> $data Validated transaction fields. */
    public function handle(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data): Transaction {
            $account = Account::ownedBy($user)->lockForUpdate()->findOrFail($data['account_id']);
            Category::ownedBy($user)->where('type', $data['type'])->lockForUpdate()->findOrFail($data['category_id']);

            $transaction = $user->transactions()->create($data);
            $operator = $data['type'] === 'income' ? '+' : '-';

            // Bound DECIMAL arithmetic stays in MySQL; no PHP floating point balance calculations.
            $updated = DB::update("UPDATE accounts SET balance = balance {$operator} CAST(? AS DECIMAL(15, 2)), updated_at = ? WHERE id = ? AND user_id = ?", [
                $data['amount'], now(), $account->id, $user->id,
            ]);

            if ($updated !== 1) {
                throw new \RuntimeException('The account balance was not updated.');
            }

            return $transaction;
        }, 3);
    }
}
