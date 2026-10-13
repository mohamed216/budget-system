<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Models\Category;
use App\Models\ChartAccount;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
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
            $integration = DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->lockForUpdate()->first();
            $selected = DB::table('operational_accounting_category_scope')
                ->where('category_id', $categoryId)->where('user_id', $actor->id)->lockForUpdate()->first() !== null;
            $mapping = DB::table('operational_category_mappings')
                ->where('category_id', $categoryId)->where('user_id', $actor->id)->lockForUpdate()->first();
            $chart = ChartAccount::ownedBy($actor)->whereKey($chartAccountId)->lockForUpdate()->firstOrFail();
            $requiredType = $category->type === 'income' ? 'revenue' : ($category->type === 'expense' ? 'expense' : null);
            if ($requiredType === null || ! $chart->is_active || $chart->type !== $requiredType) {
                throw ValidationException::withMessages(['chart_account_id' => 'Category requires an active matching chart account.']);
            }

            $sameClassification = $mapping !== null && (string) $mapping->chart_account_id === (string) $chart->id
                && $mapping->cash_flow_activity === $cashFlowActivity;
            if ($sameClassification && $mapping->reviewed_by !== null && $mapping->reviewed_at !== null) {
                return (array) $mapping;
            }
            $changes = $selected && ! $sameClassification ? (new OperationalReconciliationInvalidation)->changesFor($integration) : [];
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

            if ($changes !== []) {
                DB::table('operational_accounting_integrations')->where('user_id', $actor->id)->update($changes + ['updated_at' => now()]);
            }

            return (array) DB::table('operational_category_mappings')
                ->where('category_id', $categoryId)->where('user_id', $actor->id)->firstOrFail();
        }, 3);
    }
}
