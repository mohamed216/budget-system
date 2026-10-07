<?php

namespace App\Accounting\Queries;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class IncomeStatementQuery
{
    public function execute(User $owner, string $dateFrom, string $dateTo): array
    {
        Validator::make(['date_from' => $dateFrom, 'date_to' => $dateTo], [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        $snapshot = (new FinancialStatementSnapshot)->read($owner, $dateFrom, $dateTo);
        $revenue = [];
        $expenses = [];
        foreach ($snapshot['rows'] as $row) {
            if ($row->account_id === null) {
                continue;
            }
            if ($row->type === 'revenue') {
                $revenue[] = $this->account($row, $row->credit_net);
            } elseif ($row->type === 'expense') {
                $expenses[] = $this->account($row, $row->debit_net);
            }
        }

        $totals = $snapshot['totals'];

        return [
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo],
            'currency' => $snapshot['currency'],
            'revenue_accounts' => $revenue,
            'expense_accounts' => $expenses,
            'totals' => [
                'total_revenue' => $totals->total_revenue,
                'total_expense' => $totals->total_expense,
                'net_profit_loss' => $totals->unclosed_profit_loss,
            ],
        ];
    }

    private function account(object $row, string $amount): array
    {
        return ['id' => $row->account_id, 'code' => $row->code, 'name' => $row->name,
            'is_active' => (bool) $row->is_active, 'amount' => $amount];
    }
}
