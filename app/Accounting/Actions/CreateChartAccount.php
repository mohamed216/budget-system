<?php

namespace App\Accounting\Actions;

use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateChartAccount
{
    public function execute(User $actor, string $code, string $name, string $type, bool $isActive = true, ?int $parentId = null): ChartAccount
    {
        $fields = ChartAccountInput::validate($code, $name, $type, $isActive, $parentId);

        return DB::transaction(function () use ($actor, $fields, $parentId) {
            $accounts = ChartHierarchy::lock($actor);
            ChartHierarchy::validateParent($accounts, $parentId);

            return $actor->chartAccounts()->create($fields);
        }, 3);
    }
}
