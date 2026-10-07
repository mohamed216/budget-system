<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReopenAccountingPeriod
{
    public function execute(User $actor, int $periodId): AccountingPeriod
    {
        return DB::transaction(function () use ($actor, $periodId) {
            AccountingPeriodLocks::owner($actor);
            $period = AccountingPeriodLocks::ownedPeriod($actor, $periodId);
            if (! $period->isClosed()) {
                throw new AccountingConflict('Only a closed accounting period can be reopened.');
            }

            $period->status = 'open';
            $period->save();

            return $period;
        }, 3);
    }
}
