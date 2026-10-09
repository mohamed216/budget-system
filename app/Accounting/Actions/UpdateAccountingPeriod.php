<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodInput;
use App\Accounting\AccountingPeriodLocks;
use App\Accounting\AccountingPeriodOverlap;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateAccountingPeriod
{
    public function execute(User $actor, int $periodId, string $startDate, string $endDate): AccountingPeriod
    {
        return DB::transaction(function () use ($actor, $periodId, $startDate, $endDate) {
            AccountingPeriodLocks::owner($actor);
            $period = AccountingPeriodLocks::ownedPeriod($actor, $periodId);
            if (! $period->isOpen() || $period->wasEverClosed()) {
                throw new AccountingConflict('Only a never-closed open accounting period can be updated.');
            }
            $dates = AccountingPeriodInput::dates($startDate, $endDate);
            if ((new AccountingPeriodOverlap)->exists($actor, $dates['start_date'], $dates['end_date'], $periodId, true)) {
                throw new AccountingConflict('Accounting period overlaps an existing period.',
                    reason: AccountingConflictReason::AccountingPeriodOverlap);
            }

            $period->fill($dates)->save();

            return $period;
        }, 3);
    }
}
