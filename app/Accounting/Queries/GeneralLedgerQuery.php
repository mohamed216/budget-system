<?php

namespace App\Accounting\Queries;

use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class GeneralLedgerQuery
{
    public function __construct(private readonly PostedLedgerIntegrity $integrity = new PostedLedgerIntegrity) {}

    public function execute(User $actor, int $chartAccountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        Validator::make(['date_from' => $dateFrom, 'date_to' => $dateTo], [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(['nullable', 'date_format:Y-m-d'], $dateFrom !== null ? ['after_or_equal:date_from'] : []),
        ])->validate();

        $posted = DB::table('journal_lines as jl')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.id', '=', 'jl.journal_entry_id')->on('je.user_id', '=', 'jl.user_id');
            })
            ->where('jl.user_id', $actor->getKey())->where('je.user_id', $actor->getKey())
            ->where('je.status', 'posted')->where('jl.chart_account_id', $chartAccountId);
        $opening = (clone $posted)->selectRaw('jl.chart_account_id, SUM(jl.debit) - SUM(jl.credit) AS signed_net')
            ->groupBy('jl.chart_account_id');
        if ($dateFrom === null) {
            $opening->whereRaw('1 = 0');
        } else {
            $opening->where('je.entry_date', '<', $dateFrom);
        }
        $period = clone $posted;
        if ($dateFrom !== null) {
            $period->where('je.entry_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $period->where('je.entry_date', '<=', $dateTo);
        }
        $totals = (clone $period)->selectRaw('jl.chart_account_id, SUM(jl.debit) AS debit_total, SUM(jl.credit) AS credit_total')
            ->groupBy('jl.chart_account_id');
        $movements = (clone $period)->select([
            'jl.chart_account_id', 'jl.id as journal_line_id', 'jl.journal_entry_id', 'je.entry_date', 'je.reference',
            'je.description as journal_description', 'jl.line_number', 'jl.description as line_description', 'jl.debit', 'jl.credit',
        ])->selectRaw('SUM(jl.debit - jl.credit) OVER (ORDER BY je.entry_date, jl.journal_entry_id, jl.line_number, jl.id ROWS UNBOUNDED PRECEDING) AS running_net');

        // One non-locking statement gives account metadata, opening, totals and movements one snapshot.
        // MySQL DECIMAL aggregates/window arithmetic retain exact signed two-decimal strings.
        $integrityScope = $this->integrity->postedScope($actor);
        if ($dateTo !== null) {
            $integrityScope->where('integrity_entries.entry_date', '<=', $dateTo);
        }
        $integrityScope->whereExists(function ($query) use ($chartAccountId): void {
            $query->selectRaw('1')->from('journal_lines as integrity_scope_lines')
                ->whereColumn('integrity_scope_lines.journal_entry_id', 'integrity_entries.id')
                ->whereColumn('integrity_scope_lines.user_id', 'integrity_entries.user_id')
                ->where('integrity_scope_lines.chart_account_id', $chartAccountId);
        });
        $rows = ChartAccount::ownedBy($actor)->whereKey($chartAccountId)
            ->crossJoinSub($this->integrity->summary($integrityScope), 'integrity')
            ->leftJoinSub($opening, 'opening', 'opening.chart_account_id', '=', 'chart_of_accounts.id')
            ->leftJoinSub($totals, 'totals', 'totals.chart_account_id', '=', 'chart_of_accounts.id')
            ->leftJoinSub($movements, 'movement', 'movement.chart_account_id', '=', 'chart_of_accounts.id')
            ->select(['chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.type',
                'movement.journal_line_id', 'movement.journal_entry_id', 'movement.entry_date', 'movement.reference',
                'movement.journal_description', 'movement.line_number', 'movement.line_description', 'movement.debit', 'movement.credit'])
            ->selectRaw('COALESCE(opening.signed_net, 0.00) AS opening_balance,
                COALESCE(totals.debit_total, 0.00) AS total_debit,
                COALESCE(totals.credit_total, 0.00) AS total_credit,
                COALESCE(totals.debit_total, 0.00) - COALESCE(totals.credit_total, 0.00) AS net_movement,
                COALESCE(opening.signed_net, 0.00) + COALESCE(totals.debit_total, 0.00) - COALESCE(totals.credit_total, 0.00) AS closing_balance,
                COALESCE(opening.signed_net, 0.00) + movement.running_net AS running_balance,
                integrity.currency_count, integrity.actual_currency, integrity.unbalanced_journal_count')
            ->orderBy('movement.entry_date')->orderBy('movement.journal_entry_id')->orderBy('movement.line_number')->orderBy('movement.journal_line_id')
            ->toBase()->get();
        $account = $rows->first() ?? throw (new ModelNotFoundException)->setModel(ChartAccount::class, [$chartAccountId]);
        $currency = $this->integrity->currency($account);
        $this->integrity->assertBalanced($account);

        return [
            'currency' => $currency,
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'opening_balance' => $account->opening_balance,
            'period' => ['total_debit' => $account->total_debit, 'total_credit' => $account->total_credit,
                'net_movement' => $account->net_movement, 'closing_balance' => $account->closing_balance],
            'movements' => $rows->filter(fn ($row) => $row->journal_line_id !== null)->map(fn ($row) => [
                'journal_line_id' => $row->journal_line_id, 'journal_entry_id' => $row->journal_entry_id,
                'entry_date' => $row->entry_date, 'reference' => $row->reference, 'journal_description' => $row->journal_description,
                'line_number' => $row->line_number, 'line_description' => $row->line_description,
                'debit' => $row->debit, 'credit' => $row->credit, 'running_balance' => $row->running_balance,
            ])->values()->all(),
        ];
    }
}
