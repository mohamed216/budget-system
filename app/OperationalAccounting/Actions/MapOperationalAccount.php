<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Models\Account;
use App\Models\ChartAccount;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MapOperationalAccount
{
    public function execute(User $actor, int $accountId, int $chartAccountId): array
    {
        return DB::transaction(function () use ($actor, $accountId, $chartAccountId) {
            AccountingPeriodLocks::owner($actor);
            Account::ownedBy($actor)->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            $selected = DB::table('operational_accounting_account_scope')
                ->where('account_id', $accountId)->where('user_id', $actor->id)->lockForUpdate()->first() !== null;
            $mapping = DB::table('operational_account_mappings')
                ->where('account_id', $accountId)->where('user_id', $actor->id)->lockForUpdate()->first();
            $chart = ChartAccount::ownedBy($actor)->whereKey($chartAccountId)->lockForUpdate()->firstOrFail();
            if (! $chart->is_active || $chart->type !== 'asset') {
                throw ValidationException::withMessages(['chart_account_id' => 'Operational accounts require an active asset chart account.']);
            }

            if ($mapping !== null && (string) $mapping->chart_account_id === (string) $chart->id) {
                return (array) $mapping;
            }
            $changes = $selected ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            $values = ['chart_account_id' => $chart->id, 'updated_at' => now()];
            if ($mapping === null) {
                DB::table('operational_account_mappings')->insert($values + [
                    'account_id' => $accountId, 'user_id' => $actor->id, 'created_at' => now(),
                ]);
            } else {
                DB::table('operational_account_mappings')->where('account_id', $accountId)->where('user_id', $actor->id)->update($values);
            }

            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            return (array) DB::table('operational_account_mappings')
                ->where('account_id', $accountId)->where('user_id', $actor->id)->firstOrFail();
        }, 3);
    }
}
