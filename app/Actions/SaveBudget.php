<?php

namespace App\Actions;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveBudget
{
    /** @param array{category_id: int|string, amount: string, month: int|string, year: int|string} $data Validated request data. */
    public function execute(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            // Serializes writes for this category before inspecting its period.
            Category::ownedBy($user)->where('type', 'expense')->lockForUpdate()->findOrFail($data['category_id']);

            // An unassigned legacy budget must not be claimed through updateOrCreate.
            $existing = Budget::where('category_id', $data['category_id'])
                ->where('month', $data['month'])->where('year', $data['year'])->first();
            if ($existing) {
                Gate::forUser($user)->authorize('view', $existing);
            }

            $user->budgets()->updateOrCreate(
                ['category_id' => $data['category_id'], 'month' => $data['month'], 'year' => $data['year']],
                ['amount' => $data['amount']]
            );
        }, 3);
    }
}
