<?php

namespace App\Accounting\Queries;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TrialBalanceQuery
{
    public function execute(User $actor, ?string $asOf = null): array
    {
        Validator::make(['as_of' => $asOf], ['as_of' => ['nullable', 'date_format:Y-m-d']])->validate();
        $posted = DB::table('journal_lines as jl')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.id', '=', 'jl.journal_entry_id')->on('je.user_id', '=', 'jl.user_id');
            })
            ->where('jl.user_id', $actor->getKey())->where('je.user_id', $actor->getKey())->where('je.status', 'posted')
            ->selectRaw('jl.chart_account_id, SUM(jl.debit) AS debit_total, SUM(jl.credit) AS credit_total')
            ->groupBy('jl.chart_account_id');
        if ($asOf !== null) {
            $posted->where('je.entry_date', '<=', $asOf);
        }
        // Filters stay inside the aggregate so unused/inactive owned accounts remain visible.
        $debit = 'COALESCE(posted.debit_total, 0.00)';
        $credit = 'COALESCE(posted.credit_total, 0.00)';
        $net = "({$debit} - {$credit})";
        $debitBalance = "GREATEST({$net}, 0.00)";
        $creditBalance = "GREATEST(-{$net}, 0.00)";
        $rows = ChartAccount::ownedBy($actor)
            ->leftJoinSub($posted, 'posted', 'posted.chart_account_id', '=', 'chart_of_accounts.id')
            ->select(['chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.type'])
            ->selectRaw("{$debit} AS debit_total, {$credit} AS credit_total, {$net} AS signed_net,
                {$debitBalance} AS debit_balance, {$creditBalance} AS credit_balance,
                SUM({$debit}) OVER () AS total_debits, SUM({$credit}) OVER () AS total_credits,
                SUM({$debitBalance}) OVER () AS total_debit_balances, SUM({$creditBalance}) OVER () AS total_credit_balances")
            ->orderBy('chart_of_accounts.code')->orderBy('chart_of_accounts.id')->toBase()->get();
        $first = $rows->first();
        $totals = $first === null
            ? ['total_debits' => '0.00', 'total_credits' => '0.00', 'total_debit_balances' => '0.00', 'total_credit_balances' => '0.00']
            : ['total_debits' => $first->total_debits, 'total_credits' => $first->total_credits,
                'total_debit_balances' => $first->total_debit_balances, 'total_credit_balances' => $first->total_credit_balances];
        if ($totals['total_debits'] !== $totals['total_credits'] || $totals['total_debit_balances'] !== $totals['total_credit_balances']) {
            throw new AccountingConflict('Trial balance is out of balance: posted ledger integrity must be investigated.');
        }

        return [
            'accounts' => $rows->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'type' => $row->type,
                'debit_total' => $row->debit_total, 'credit_total' => $row->credit_total, 'signed_net' => $row->signed_net,
                'debit_balance' => $row->debit_balance, 'credit_balance' => $row->credit_balance])->all(),
            'totals' => $totals,
        ];
    }
}
