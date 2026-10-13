<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\ChartAccount;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use Illuminate\Support\Facades\DB;

final class UpdateChartAccount
{
    public function execute(User $actor, int $accountId, string $code, string $name, string $type, bool $isActive, ?int $parentId = null): ChartAccount
    {
        $fields = ChartAccountInput::validate($code, $name, $type, $isActive, $parentId);

        return DB::transaction(function () use ($actor, $accountId, $fields, $parentId) {
            AccountingPeriodLocks::owner($actor);
            // Fiscal references are guard sources: acquire them before chart rows.
            $fiscalReferences = DB::table('fiscal_year_closes')->where('user_id', $actor->id)
                ->where('retained_earnings_account_id', $accountId)->orderBy('id')->sharedLock()->get(['id']);
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            $dependencies = new OperationalReconciliationChartDependencies;
            $participants = $dependencies->lockParticipation($actor->id);
            $accounts = ChartHierarchy::lockForAlreadyLockedOwner($actor);
            $account = ChartHierarchy::owned($accounts, $accountId);
            ChartHierarchy::validateParent($accounts, $parentId, $accountId);
            $account->fill($fields);
            if (! $account->isDirty()) {
                return $account;
            }
            if ($account->isDirty(['code', 'type'])) {
                $journalUse = $dependencies->lockJournalUse($actor->id, $accountId);
                $batches = DB::table('opening_balance_batches')->where('user_id', $actor->id)->orderBy('id')
                    ->sharedLock()->get(['id'])->keyBy('id');
                $openingReferences = DB::table('opening_balance_lines')->where('user_id', $actor->id)
                    ->where('chart_account_id', $accountId)->orderBy('id')->sharedLock()->get(['id', 'batch_id']);
                foreach ($openingReferences as $line) {
                    if (! $batches->has($line->batch_id)) {
                        throw new AccountingConflict('Opening dependency set changed; restart the entire mutation.',
                            reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
                    }
                }
                if ($journalUse['referenced'] || $openingReferences->isNotEmpty() || $fiscalReferences->isNotEmpty()) {
                    throw new AccountingConflict('Referenced chart account code and type cannot change.');
                }
            }
            $changes = isset($participants[$accountId]) && $account->isDirty(['type', 'is_active'])
                ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
            $account->save();
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            return $account;
        }, 3);
    }
}
