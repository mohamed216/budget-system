<?php

namespace App\Accounting\Queries;

use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class OwnedChartAccounts
{
    public function ordered(User $owner): Collection
    {
        return ChartAccount::ownedBy($owner)->orderBy('code')->orderBy('id')->get();
    }

    public function find(User $owner, string $id): ChartAccount
    {
        return ChartAccount::ownedBy($owner)->findOrFail($id);
    }
}
