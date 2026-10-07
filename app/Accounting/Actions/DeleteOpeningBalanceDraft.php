<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeleteOpeningBalanceDraft
{
    public function execute(User $actor, int $batchId): void
    {
        DB::transaction(function () use ($actor, $batchId) {
            AccountingPeriodLocks::owner($actor);
            $batch = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->lockForUpdate()->firstOrFail();
            if (! $batch->isDraft()) {
                throw new AccountingConflict('Posted opening balance batch cannot be deleted.');
            }
            $batch->lines()->lockForUpdate()->get();
            $batch->lines()->delete();
            $batch->delete();
        }, 3);
    }
}
