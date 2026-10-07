<?php

namespace App\Accounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\AccountingPeriod;
use App\Models\User;

final class PeriodGuard
{
    /** Call inside a transaction after locking the owner row, before locking a journal. */
    public function assertOpen(User $owner, string $entryDate): void
    {
        $periods = AccountingPeriod::ownedBy($owner)
            ->where('start_date', '<=', $entryDate)
            ->where('end_date', '>=', $entryDate)
            ->lockForUpdate()
            ->get(['status']);

        if ($periods->contains(fn (AccountingPeriod $period) => $period->isClosed())) {
            throw new AccountingConflict('Accounting period for the journal date is closed.');
        }
    }
}
