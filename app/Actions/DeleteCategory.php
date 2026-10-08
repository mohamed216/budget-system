<?php

namespace App\Actions;

use App\Actions\Exceptions\CategoryHasReferences;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteCategory
{
    public function execute(User $user, Category $category): void
    {
        Gate::forUser($user)->authorize('delete', $category);

        DB::transaction(function () use ($user, $category): void {
            $locked = Category::query()->lockForUpdate()->findOrFail($category->id);
            Gate::forUser($user)->authorize('delete', $locked);
            if ($locked->transactions()->exists() || $locked->budgets()->exists()) {
                throw new CategoryHasReferences;
            }
            $locked->delete();
        });
    }
}
