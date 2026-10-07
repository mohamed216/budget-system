<?php

namespace App\Accounting;

use App\Models\AccountingPeriod;
use App\Models\User;

final class AccountingPeriodLocks
{
    /** Acquire before any accounting period row/query lock in the same transaction. */
    public static function owner(User $actor): void
    {
        User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
    }

    public static function ownedPeriod(User $actor, int $periodId): AccountingPeriod
    {
        return AccountingPeriod::ownedBy($actor)->whereKey($periodId)->lockForUpdate()->firstOrFail();
    }
}
