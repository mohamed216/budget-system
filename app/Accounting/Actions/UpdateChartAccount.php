<?php

namespace App\Accounting\Actions;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateChartAccount
{
    public function execute(User $actor, int $accountId, string $code, string $name, string $type, bool $isActive, ?int $parentId = null): ChartAccount
    {
        $fields = ChartAccountInput::validate($code, $name, $type, $isActive, $parentId);

        return DB::transaction(function () use ($actor, $accountId, $fields, $parentId) {
            $accounts = ChartHierarchy::lock($actor);
            $account = ChartHierarchy::owned($accounts, $accountId);
            ChartHierarchy::validateParent($accounts, $parentId, $accountId);
            // Keep reference checks non-locking to avoid reversing the draft line/account lock order.
            $referenced = $account->journalLines()->exists() || $account->openingBalanceLines()->exists()
                || $account->fiscalYearClosesAsRetainedEarnings()->exists();
            if ($referenced && ($account->code !== $fields['code'] || $account->type !== $fields['type'])) {
                throw new AccountingConflict('Referenced chart account code and type cannot change.');
            }
            $account->fill($fields)->save();

            return $account;
        }, 3);
    }
}
