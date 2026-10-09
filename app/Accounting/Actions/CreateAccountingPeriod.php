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

final class CreateAccountingPeriod
{
    public function execute(User $actor, string $startDate, string $endDate): AccountingPeriod
    {
        $dates = AccountingPeriodInput::dates($startDate, $endDate);

        return DB::transaction(function () use ($actor, $dates) {
            AccountingPeriodLocks::owner($actor);
            if ((new AccountingPeriodOverlap)->exists($actor, $dates['start_date'], $dates['end_date'], lockForUpdate: true)) {
                throw new AccountingConflict('Accounting period overlaps an existing period.',
                    reason: AccountingConflictReason::AccountingPeriodOverlap);
            }

            return $actor->accountingPeriods()->create($dates);
        }, 3);
    }
}
