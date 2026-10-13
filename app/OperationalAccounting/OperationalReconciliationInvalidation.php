<?php

namespace App\OperationalAccounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;

/** Policy only: callers own relevance, current locks, transactions and persistence. */
final class OperationalReconciliationInvalidation
{
    public function changesFor(?object $integration): array
    {
        if ($integration === null) {
            return [];
        }
        $reference = $integration->reconciliation_reference ?? null;
        $time = $integration->reconciled_at ?? null;
        if (($reference === null) !== ($time === null)) {
            throw new AccountingConflict('Reconciliation evidence must be a complete pair.', reason: AccountingConflictReason::ReconciliationState);
        }
        $bound = $reference !== null;
        switch ($integration->state ?? null) {
            case 'legacy':
                if (! $bound) {
                    return [];
                }
                break;
            case 'reconciling':
                return $bound ? ['reconciliation_reference' => null, 'reconciled_at' => null] : [];
            case 'ready':
                if ($bound) {
                    return ['state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null];
                }
                break;
        }
        throw new AccountingConflict('Reconciliation cannot be invalidated in this integration state.', reason: AccountingConflictReason::ReconciliationState);
    }
}
