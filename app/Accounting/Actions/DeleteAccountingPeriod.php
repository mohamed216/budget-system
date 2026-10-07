<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteAccountingPeriod
{
    public function execute(User $actor, int $periodId): void
    {
        DB::transaction(function () use ($actor, $periodId) {
            AccountingPeriodLocks::owner($actor);
            $period = AccountingPeriodLocks::ownedPeriod($actor, $periodId);
            if (! $period->isOpen() || $period->wasEverClosed()) {
                throw new AccountingConflict('Only a never-closed open accounting period can be deleted.');
            }

            $period->delete();
        }, 3);
    }
}
