<?php

namespace App\Accounting\Actions;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteChartAccount
{
    public function execute(User $actor, int $accountId): void
    {
        DB::transaction(function () use ($actor, $accountId) {
            $accounts = ChartHierarchy::lock($actor);
            $account = ChartHierarchy::owned($accounts, $accountId);
            if ($accounts->contains('parent_id', $accountId) || $account->journalLines()->exists()) {
                throw new AccountingConflict('Chart account with children or journal lines cannot be deleted.');
            }
            if ($account->openingBalanceLines()->exists()) {
                throw new AccountingConflict('Chart account with opening balance lines cannot be deleted.');
            }
            $account->delete();
        }, 3);
    }
}
