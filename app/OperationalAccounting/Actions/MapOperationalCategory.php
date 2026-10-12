<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MapOperationalCategory
{
    private const ACTIVITIES = ['operating', 'investing', 'financing'];

    public function execute(User $actor, int $categoryId, int $chartAccountId, string $cashFlowActivity): array
    {
        if (! in_array($cashFlowActivity, self::ACTIVITIES, true)) {
            throw ValidationException::withMessages(['cash_flow_activity' => 'Select operating, investing, or financing.']);
        }

        return DB::transaction(function () use ($actor, $categoryId, $chartAccountId, $cashFlowActivity) {
            AccountingPeriodLocks::owner($actor);
            $category = Category::ownedBy($actor)->whereKey($categoryId)->lockForUpdate()->firstOrFail();
            $mapping = DB::table('operational_category_mappings')
                ->where('category_id', $categoryId)->where('user_id', $actor->id)->lockForUpdate()->first();
            $chart = ChartAccount::ownedBy($actor)->whereKey($chartAccountId)->lockForUpdate()->firstOrFail();
            $requiredType = $category->type === 'income' ? 'revenue' : ($category->type === 'expense' ? 'expense' : null);
            if ($requiredType === null || ! $chart->is_active || $chart->type !== $requiredType) {
                throw ValidationException::withMessages(['chart_account_id' => 'Category requires an active matching chart account.']);
            }

            $values = [
                'chart_account_id' => $chart->id, 'cash_flow_activity' => $cashFlowActivity,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now(),
            ];
            if ($mapping === null) {
                DB::table('operational_category_mappings')->insert($values + [
                    'category_id' => $categoryId, 'user_id' => $actor->id, 'created_at' => now(),
                ]);
            } else {
                DB::table('operational_category_mappings')->where('category_id', $categoryId)->where('user_id', $actor->id)->update($values);
            }

            return (array) DB::table('operational_category_mappings')
                ->where('category_id', $categoryId)->where('user_id', $actor->id)->firstOrFail();
        }, 3);
    }
}
