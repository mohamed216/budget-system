<?php

namespace App\Accounting;

use App\Models\AccountingPeriod;
use App\Models\User;

final class AccountingPeriodOverlap
{
    /** Caller must coordinate period writes under the owner's transaction lock. */
    public function exists(User $owner, string $startDate, string $endDate, ?int $excludeId = null, bool $lockForUpdate = false): bool
    {
        $query = AccountingPeriod::ownedBy($owner)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($excludeId !== null) {
            $query->whereKeyNot($excludeId);
        }
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        // A row read, unlike an EXISTS subquery, places FOR UPDATE on accounting_periods.
        return $query->first(['id']) !== null;
    }
}
