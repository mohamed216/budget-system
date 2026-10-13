<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationSnapshot;
use App\OperationalAccounting\OperationalReconciliationValidator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class MarkOperationalAccountingReady
{
    public function __construct(private readonly OperationalReconciliationValidator $validator = new OperationalReconciliationValidator) {}

    /** @return array{user_id: int, state: string, reconciliation_reference: string, reconciled_at: string} */
    public function execute(User $owner): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new AccountingConflict('READY requires its own outer transaction.', reason: AccountingConflictReason::ReconciliationState);
        }
        if (! $owner->exists || $owner->getKey() === null || $owner->getKey() <= 0) {
            throw (new ModelNotFoundException)->setModel(User::class);
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($owner): array {
                    AccountingPeriodLocks::owner($owner);
                    $snapshot = (new OperationalReconciliationSnapshot)->load($owner->getKey());
                    $this->validator->validateBound($snapshot, 'reconciling');
                    $updated = DB::table('operational_accounting_integrations')->where('user_id', $owner->getKey())
                        ->where('state', 'reconciling')->update(['state' => 'ready']);
                    if ($updated !== 1) {
                        throw new AccountingConflict('Integration is no longer reconciling.', reason: AccountingConflictReason::ReconciliationState);
                    }
                    $gate = DB::table('operational_accounting_integrations')->where('user_id', $owner->getKey())->sharedLock()->firstOrFail();

                    return ['user_id' => (int) $gate->user_id, 'state' => $gate->state,
                        'reconciliation_reference' => $gate->reconciliation_reference, 'reconciled_at' => $gate->reconciled_at];
                }, 3);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable READY retry state.');
    }
}
