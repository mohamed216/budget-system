<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CaptureOperationalReconciliationBoundary
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
            if ($gate === null || $gate->state !== 'reconciling') {
                throw new AccountingConflict('Boundary capture requires an existing reconciling integration gate.');
            }

            // Never use MAX from a consistent snapshot or accept a caller cutoff.
            // Owner serialization and this current read define the captured edge.
            $latest = DB::table('transactions')->where('user_id', $owner->id)
                ->orderByDesc('id')->lockForUpdate()->first(['id']);
            $boundary = $latest->id ?? 0;
            if ($gate->transaction_high_water_id !== null && (string) $gate->transaction_high_water_id === (string) $boundary) {
                return (array) $gate;
            }
            $changes = (new OperationalReconciliationInvalidation)->changesFor($gate);
            DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update($changes + [
                'transaction_high_water_id' => $boundary, 'updated_at' => now(),
            ]);

            return (array) DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->firstOrFail();
        }, 3);
    }
}
