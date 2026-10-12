<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class BeginOperationalReconciliation
{
    public function execute(User $owner): array
    {
        return DB::transaction(function () use ($owner): array {
            if (! $owner->exists || $owner->getKey() === null || $owner->getKey() <= 0) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }
            AccountingPeriodLocks::owner($owner);
            $gate = DB::table('operational_accounting_integrations')
                ->where('user_id', $owner->id)->lockForUpdate()->first();

            if ($gate !== null && $gate->state === 'reconciling') {
                return (array) $gate;
            }
            if ($gate !== null && $gate->state !== 'legacy') {
                throw new AccountingConflict('Reconciliation can begin only from legacy or an absent integration gate.');
            }

            // A locking read sees current committed rows even if the caller's
            // repeatable-read transaction established an earlier snapshot.
            $latest = DB::table('transactions')->where('user_id', $owner->id)
                ->orderByDesc('id')->lockForUpdate()->first(['id']);
            $values = ['state' => 'reconciling', 'transaction_high_water_id' => $latest->id ?? 0,
                'reconciliation_reference' => null, 'reconciled_at' => null, 'updated_at' => now()];
            if ($gate === null) {
                DB::table('operational_accounting_integrations')->insert($values + [
                    'user_id' => $owner->id, 'created_at' => now(),
                ]);
            } else {
                DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update($values);
            }

            return (array) DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->firstOrFail();
        }, 3);
    }
}
