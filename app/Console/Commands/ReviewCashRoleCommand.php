<?php

namespace App\Console\Commands;

use App\Accounting\Actions\ReviewCashAccountRole;
use App\Models\ChartAccount;

final class ReviewCashRoleCommand extends CashFlowOperatorCommand
{
    protected $signature = 'accounting:cash-role-review {user : Owner user ID} {account : Owned chart account ID} {role : non_cash, cash, or cash_equivalent}';

    protected $description = 'Review one owned chart account cash role';

    public function handle(ReviewCashAccountRole $review): int
    {
        return $this->safely(function () use ($review): void {
            $owner = $this->owner();
            $accountId = $this->positiveId('account');
            $before = ChartAccount::ownedBy($owner)->whereKey($accountId)->firstOrFail()->cash_role;
            $after = $review->execute($owner, $accountId, (string) $this->argument('role'));
            $this->line('Account #'.$accountId.': '.($before ?? 'unreviewed').' -> '.$after->cash_role);
        });
    }
}
