<?php

namespace App\Accounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\OpeningBalanceInput;
use App\Accounting\OpeningBalanceTotals;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveOpeningBalanceDraft
{
    public function execute(User $actor, string $openingDate, string $currency, array $lines, ?int $batchId = null): OpeningBalanceBatch
    {
        $header = OpeningBalanceInput::header($openingDate, $currency);

        return DB::transaction(function () use ($actor, $batchId, $header, $lines) {
            AccountingPeriodLocks::owner($actor);
            if ($batchId !== null) {
                // Resolve ownership before checking the target date; no batch lock precedes the period lock.
                $candidate = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->firstOrFail();
                if ($candidate->isPosted()) {
                    throw new AccountingConflict('Posted opening balance batch cannot be modified.');
                }
            }
            (new PeriodGuard)->assertOpen($actor, $header['opening_date']);

            if ($batchId === null) {
                $batch = new OpeningBalanceBatch($header);
                $batch->user_id = $actor->getKey();
            } else {
                $batch = OpeningBalanceBatch::ownedBy($actor)->whereKey($batchId)->lockForUpdate()->firstOrFail();
                if (! $batch->isDraft()) {
                    throw new AccountingConflict('Posted opening balance batch cannot be modified.');
                }
            }
            if ($batchId !== null) {
                $batch->lines()->lockForUpdate()->get();
            }

            $normalized = OpeningBalanceInput::normalizeLines($lines);
            try {
                OpeningBalanceTotals::assertBalanced($normalized);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
            }

            $accountIds = array_column($normalized, 'chart_account_id');
            sort($accountIds, SORT_NUMERIC);
            $accounts = ChartAccount::ownedBy($actor)->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
            if ($accounts->count() !== count($accountIds) || $accounts->contains(fn ($account) => ! $account->is_active)) {
                throw ValidationException::withMessages(['lines' => 'Opening balance accounts must exist, belong to the actor, and be active.']);
            }

            if ($batchId === null) {
                $batch->save();
            } else {
                $batch->lines()->delete();
                $batch->fill($header);
                $batch->save();
            }
            foreach ($normalized as $fields) {
                $line = new OpeningBalanceLine($fields);
                $line->user_id = $actor->getKey();
                $batch->lines()->save($line);
            }

            return $batch->load('lines');
        }, 3);
    }
}
