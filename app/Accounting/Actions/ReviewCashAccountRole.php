<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\CashAccountRole;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\ChartAccount;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationChartDependencies;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
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
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            $dependencies = new OperationalReconciliationChartDependencies;
            $participants = $dependencies->lockParticipation($actor->id);
            $account = ChartAccount::ownedBy($actor)->whereKey($accountId)->lockForUpdate()->firstOrFail();

            if ($reviewedRole->isCash() && $account->type !== 'asset') {
                throw ValidationException::withMessages(['cash_role' => 'Cash accounts must be assets.']);
            }
            if ($account->cash_role === $reviewedRole->value) {
                return $account;
            }
            $journalUse = $dependencies->lockJournalUse($actor->id, $accountId);
            if ($account->cash_role !== null && $journalUse['posted_journal_ids'] !== []) {
                throw new AccountingConflict('Cash role is frozen after posted journal use.', reason: AccountingConflictReason::CashRoleFrozen);
            }
            $relevant = isset($participants[$accountId]);
            if (! $relevant) {
                $relevant = $dependencies->lockBoundJournalDependency($actor->id, $integration, $journalUse['posted_journal_ids']);
            }
            $changes = $relevant ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];

            $account->cash_role = $reviewedRole->value;
            $account->save();
            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            return $account;
        }, 3);
    }
}
