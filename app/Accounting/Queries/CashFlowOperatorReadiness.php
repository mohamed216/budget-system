<?php

namespace App\Accounting\Queries;

use App\Models\ChartAccount;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only operator work list; unresolved roles can hide additional cash journals. */
final class CashFlowOperatorReadiness
{
    public function inspect(User $owner, string $through): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $through);
        if ($date === false || $date->format('Y-m-d') !== $through) {
            throw ValidationException::withMessages(['through' => 'Provide a valid YYYY-MM-DD date.']);
        }

        $unresolved = ChartAccount::ownedBy($owner)->whereNull('cash_role')
            ->orderBy('id')->get(['id', 'code', 'name'])
            ->map(fn ($account) => [
                'account_id' => $account->id, 'code' => $account->code, 'name' => $account->name,
            ])->all();

        $incomplete = DB::table('journal_entries as je')
            ->leftJoin('opening_balance_batches as ob', function ($join): void {
                $join->on('ob.journal_entry_id', '=', 'je.id')->on('ob.user_id', '=', 'je.user_id');
            })
            ->leftJoin('fiscal_year_closes as fyc', function ($join): void {
                $join->on('fyc.journal_entry_id', '=', 'je.id')->on('fyc.user_id', '=', 'je.user_id');
            })
            ->leftJoin('journal_entries as original', function ($join): void {
                $join->on('original.id', '=', 'je.reversal_of_id')->on('original.user_id', '=', 'je.user_id');
            })
            ->leftJoin('opening_balance_batches as original_ob', function ($join): void {
                $join->on('original_ob.journal_entry_id', '=', 'original.id')->on('original_ob.user_id', '=', 'je.user_id');
            })
            ->leftJoin('fiscal_year_closes as original_fyc', function ($join): void {
                $join->on('original_fyc.journal_entry_id', '=', 'original.id')->on('original_fyc.user_id', '=', 'je.user_id');
            })
            ->leftJoin('cash_flow_journal_completions as completion', function ($join): void {
                $join->on('completion.journal_entry_id', '=', 'je.id')->on('completion.user_id', '=', 'je.user_id');
            })
            ->where('je.user_id', $owner->id)->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $through)
            ->whereNull('ob.id')->whereNull('fyc.id')
            ->whereNull('original_ob.id')->whereNull('original_fyc.id')->whereNull('completion.id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('journal_lines as jl')
                    ->join('chart_of_accounts as ca', function ($join): void {
                        $join->on('ca.id', '=', 'jl.chart_account_id')->on('ca.user_id', '=', 'jl.user_id');
                    })
                    ->whereColumn('jl.journal_entry_id', 'je.id')->whereColumn('jl.user_id', 'je.user_id')
                    ->whereIn('ca.cash_role', ['cash', 'cash_equivalent']);
            })
            ->orderBy('je.entry_date')->orderBy('je.id')
            ->get(['je.id', 'je.entry_date', 'je.reference', 'je.reversal_of_id'])
            ->map(fn ($journal) => [
                'journal_id' => $journal->id, 'entry_date' => $journal->entry_date,
                'reference' => $journal->reference,
                'manual_completion_supported' => $journal->reversal_of_id === null,
            ])->all();

        return ['unresolved_accounts' => $unresolved, 'incomplete_journals' => $incomplete];
    }
}
