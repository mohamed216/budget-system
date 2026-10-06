<?php

namespace App\Accounting\Actions;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ChartHierarchy
{
    public static function lock(User $actor): Collection
    {
        // Serialize hierarchy writers, including insertion into an empty chart.
        User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        return ChartAccount::ownedBy($actor)->orderBy('id')->lockForUpdate()->get();
    }

    public static function owned(Collection $accounts, int $id): ChartAccount
    {
        return $accounts->find($id) ?? throw (new ModelNotFoundException)->setModel(ChartAccount::class, [$id]);
    }

    public static function validateParent(Collection $accounts, ?int $parentId, ?int $accountId = null): void
    {
        $visited = [];
        while ($parentId !== null) {
            if ($parentId === $accountId || isset($visited[$parentId])) {
                throw new AccountingConflict('Chart hierarchy cycle is not allowed.');
            }
            $visited[$parentId] = true;
            $parentId = self::owned($accounts, $parentId)->parent_id;
        }
    }
}
