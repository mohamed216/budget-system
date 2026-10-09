<?php

namespace App\Queries;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class LegacyPageRelations
{
    /** @param array<string, mixed> $filters */
    public function transactions(User $owner, array $filters): Collection
    {
        $rows = Transaction::ownedBy($owner)
            ->with([
                'account' => fn ($query) => $query->ownedBy($owner),
                'category' => fn ($query) => $query->ownedBy($owner),
            ])
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['month'] ?? null, fn ($query, $month) => $query->whereMonth('date', $month))
            ->when($filters['year'] ?? null, fn ($query, $year) => $query->whereYear('date', $year))
            ->orderByDesc('date')->get();

        if ($rows->contains(fn (Transaction $row) => $row->account === null || $row->category === null)) {
            throw new LegacyRelationIntegrity;
        }

        return $rows;
    }

    public function budgets(User $owner): Collection
    {
        $rows = Budget::ownedBy($owner)
            ->with(['category' => fn ($query) => $query->ownedBy($owner)])
            ->get();

        if ($rows->contains(fn (Budget $row) => $row->category === null)) {
            throw new LegacyRelationIntegrity;
        }

        return $rows;
    }
}
