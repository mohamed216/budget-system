<?php

namespace App\Accounting\Queries;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Posted-journal currency and per-journal balance summary for one report snapshot. */
final class PostedLedgerIntegrity
{
    /** Only pass SQL identifiers fixed by the report implementation, never request input. */
    public static function currencyCountExpression(string $currencyColumn): string
    {
        return "COUNT(DISTINCT CAST({$currencyColumn} AS BINARY))";
    }

    public static function journalDeltaExpression(string $lineAlias): string
    {
        return "COALESCE(SUM({$lineAlias}.debit), 0.00) - COALESCE(SUM({$lineAlias}.credit), 0.00)";
    }

    public static function unbalancedCountExpression(string $deltaColumn): string
    {
        return "COALESCE(SUM(CASE WHEN {$deltaColumn} <> 0.00 THEN 1 ELSE 0 END), 0)";
    }

    public function postedScope(User $owner): Builder
    {
        return DB::table('journal_entries as integrity_entries')
            ->select('integrity_entries.id', 'integrity_entries.user_id', 'integrity_entries.currency')
            ->where('integrity_entries.user_id', $owner->getKey())
            ->where('integrity_entries.status', 'posted');
    }

    public function summary(Builder $posted): Builder
    {
        $journals = DB::query()->fromSub($posted, 'integrity_scope')
            ->leftJoin('journal_lines as integrity_lines', function ($join): void {
                $join->on('integrity_lines.journal_entry_id', '=', 'integrity_scope.id')
                    ->on('integrity_lines.user_id', '=', 'integrity_scope.user_id');
            })
            ->selectRaw('integrity_scope.id, integrity_scope.currency, '
                .self::journalDeltaExpression('integrity_lines').' AS journal_delta')
            ->groupBy('integrity_scope.id', 'integrity_scope.currency');

        return DB::query()->fromSub($journals, 'integrity_journals')
            ->selectRaw(self::currencyCountExpression('integrity_journals.currency').' AS currency_count,
                MIN(integrity_journals.currency) AS actual_currency, '
                .self::unbalancedCountExpression('integrity_journals.journal_delta').' AS unbalanced_journal_count');
    }

    public function currency(object $summary): string
    {
        return ReportingCurrency::resolve((int) $summary->currency_count, $summary->actual_currency);
    }

    public function assertBalanced(object $summary): void
    {
        if ((int) $summary->unbalanced_journal_count > 0) {
            throw new AccountingConflict('Posted ledger is out of balance: an individual journal is unbalanced.',
                reason: AccountingConflictReason::PostedLedgerUnbalanced);
        }
    }
}
