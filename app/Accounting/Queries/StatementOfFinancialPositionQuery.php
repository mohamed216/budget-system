<?php

namespace App\Accounting\Queries;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class StatementOfFinancialPositionQuery
{
    public function execute(User $owner, string $asOf): array
    {
        Validator::make(['as_of' => $asOf], ['as_of' => ['required', 'date_format:Y-m-d']])->validate();

        $snapshot = (new FinancialStatementSnapshot)->read($owner, null, $asOf);
        $accounts = ['assets' => [], 'liabilities' => [], 'equity' => []];
        foreach ($snapshot['rows'] as $row) {
            if ($row->account_id === null) {
                continue;
            }
            $section = match ($row->type) {
                'asset' => 'assets',
                'liability' => 'liabilities',
                'equity' => 'equity',
                default => null,
            };
            if ($section !== null) {
                $accounts[$section][] = ['id' => $row->account_id, 'code' => $row->code, 'name' => $row->name,
                    'is_active' => (bool) $row->is_active,
                    'amount' => $section === 'assets' ? $row->debit_net : $row->credit_net];
            }
        }

        $totals = $snapshot['totals'];

        return [
            'as_of' => $asOf,
            'currency' => $snapshot['currency'],
            ...$accounts,
            'totals' => [
                'assets' => $totals->total_assets,
                'liabilities' => $totals->total_liabilities,
                'equity' => $totals->total_equity,
                'unclosed_cumulative_profit_loss' => $totals->unclosed_profit_loss,
                'liabilities_equity_and_unclosed_profit_loss' => $totals->liabilities_equity_and_unclosed_profit_loss,
                'equation_difference' => $totals->equation_difference,
            ],
        ];
    }
}
