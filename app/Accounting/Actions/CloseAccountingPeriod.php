<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CloseAccountingPeriod
{
    public function execute(User $actor, int $periodId): AccountingPeriod
    {
        return DB::transaction(function () use ($actor, $periodId) {
            AccountingPeriodLocks::owner($actor);
            $period = AccountingPeriodLocks::ownedPeriod($actor, $periodId);
            if (! $period->isOpen()) {
                throw new AccountingConflict('Only an open accounting period can be closed.');
            }

            $period->status = 'closed';
            $period->first_closed_at ??= now();
            $period->save();

            return $period;
        }, 3);
    }
}
