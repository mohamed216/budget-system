<?php

namespace App\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class PostTransaction
{
    /** @param array<string, mixed> $data Validated transaction fields. */
    public function handle(User $user, array $data): Transaction
    {
        return DB::transaction(function () use ($user, $data): Transaction {
            if (! $user->exists || $user->getKey() === null || $user->getKey() <= 0) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }
            AccountingPeriodLocks::owner($user);
            $account = Account::ownedBy($user)->lockForUpdate()->findOrFail($data['account_id']);
            Category::ownedBy($user)->where('type', $data['type'])->lockForUpdate()->findOrFail($data['category_id']);
            $integration = DB::table('operational_accounting_integrations')
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if ($integration !== null && ! in_array($integration->state, ['legacy', 'reconciling', 'ready'], true)) {
                throw new AccountingConflict('Legacy transaction posting is unavailable for this integration state.');
            }

            $transaction = $user->transactions()->create($data);
            $operator = $data['type'] === 'income' ? '+' : '-';

            // Bound DECIMAL arithmetic stays in MySQL; no PHP floating point balance calculations.
            $updated = DB::update("UPDATE accounts SET balance = balance {$operator} CAST(? AS DECIMAL(15, 2)), updated_at = ? WHERE id = ? AND user_id = ?", [
                $data['amount'], now(), $account->id, $user->id,
            ]);

            if ($updated !== 1) {
                throw new \RuntimeException('The account balance was not updated.');
            }

            // A successful legacy create invalidates completion, never the reviewed
            // participation plan or its captured historical boundary. Demotion and
            // evidence clearing must be one update to retain the ready CHECK rule.
            if ($integration !== null && ($integration->state === 'ready'
                || $integration->reconciliation_reference !== null || $integration->reconciled_at !== null)) {
                $invalidated = DB::table('operational_accounting_integrations')->where('user_id', $user->id)->update([
                    'state' => $integration->state === 'ready' ? 'reconciling' : $integration->state,
                    'reconciliation_reference' => null, 'reconciled_at' => null, 'updated_at' => now(),
                ]);
                if ($invalidated !== 1) {
                    throw new \RuntimeException('Reconciliation evidence was not invalidated.');
                }
            }

            return $transaction;
        }, 3);
    }
}
