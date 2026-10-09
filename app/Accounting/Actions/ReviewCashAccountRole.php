<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashAccountRole;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewCashAccountRole
{
    public function execute(User $actor, int $accountId, string $role): ChartAccount
    {
        $reviewedRole = CashAccountRole::tryFrom($role)
            ?? throw ValidationException::withMessages(['cash_role' => 'Select a valid cash role.']);

        return DB::transaction(function () use ($actor, $accountId, $reviewedRole) {
            AccountingPeriodLocks::owner($actor);
            $account = ChartAccount::ownedBy($actor)->whereKey($accountId)->lockForUpdate()->firstOrFail();

            if ($reviewedRole->isCash() && $account->type !== 'asset') {
                throw ValidationException::withMessages(['cash_role' => 'Cash accounts must be assets.']);
            }
            if ($account->cash_role === $reviewedRole->value) {
                return $account;
            }
            if ($account->cash_role !== null && $account->journalLines()
                ->whereHas('journalEntry', fn ($query) => $query->ownedBy($actor)->where('status', 'posted'))
                ->exists()) {
                throw new AccountingConflict('Cash role is frozen after posted journal use.', reason: AccountingConflictReason::CashRoleFrozen);
            }

            $account->cash_role = $reviewedRole->value;
            $account->save();

            return $account;
        }, 3);
    }
}
