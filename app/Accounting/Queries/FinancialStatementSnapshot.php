<?php

namespace App\Accounting\Queries;

use App\Accounting\Exceptions\AccountingConflict;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class FinancialStatementSnapshot
{
    /** One non-locking MySQL statement supplies scoped account balances, currency, and totals. */
    public function read(User $owner, ?string $dateFrom, string $dateTo, bool $excludeFiscalClosingJournals = false): array
    {
        $datePredicate = $dateFrom === null
            ? 'AND je.entry_date <= ?'
            : 'AND je.entry_date >= ? AND je.entry_date <= ?';
        $closingPredicate = $excludeFiscalClosingJournals
            ? 'AND NOT EXISTS (SELECT 1 FROM fiscal_year_closes fyc WHERE fyc.user_id = je.user_id AND fyc.journal_entry_id = je.id)'
            : '';
        $bindings = [$owner->getKey(), ...($dateFrom === null ? [] : [$dateFrom]), $dateTo, $owner->getKey(), $owner->getKey()];
        $currencyCount = PostedLedgerIntegrity::currencyCountExpression('currency');
        $journalDelta = PostedLedgerIntegrity::journalDeltaExpression('jl');
        $unbalancedCount = PostedLedgerIntegrity::unbalancedCountExpression('delta');

        $rows = DB::select(<<<SQL
WITH posted_scope AS (
    SELECT je.id, je.user_id, je.currency
    FROM journal_entries je
    WHERE je.user_id = ? AND je.status = 'posted' {$datePredicate} {$closingPredicate}
),
currency_summary AS (
    SELECT {$currencyCount} AS currency_count, MIN(currency) AS actual_currency
    FROM posted_scope
),
journal_deltas AS (
    SELECT pe.id, {$journalDelta} AS delta
    FROM posted_scope pe
    LEFT JOIN journal_lines jl ON jl.journal_entry_id = pe.id AND jl.user_id = pe.user_id
    GROUP BY pe.id
),
journal_integrity AS (
    SELECT {$unbalancedCount} AS unbalanced_journal_count
    FROM journal_deltas
),
line_totals AS (
    SELECT jl.chart_account_id, jl.user_id, SUM(jl.debit) AS debit_total, SUM(jl.credit) AS credit_total
    FROM journal_lines jl
    INNER JOIN posted_scope pe ON pe.id = jl.journal_entry_id AND pe.user_id = jl.user_id
    WHERE jl.user_id = ?
    GROUP BY jl.chart_account_id, jl.user_id
),
account_nets AS (
    SELECT coa.id, coa.code, coa.name, coa.type, coa.is_active,
        COALESCE(lt.debit_total, 0.00) - COALESCE(lt.credit_total, 0.00) AS debit_net,
        COALESCE(lt.credit_total, 0.00) - COALESCE(lt.debit_total, 0.00) AS credit_net
    FROM chart_of_accounts coa
    LEFT JOIN line_totals lt ON lt.chart_account_id = coa.id AND lt.user_id = coa.user_id
    WHERE coa.user_id = ?
),
statement_totals AS (
    SELECT
        COALESCE(SUM(CASE WHEN type = 'asset' THEN debit_net ELSE 0.00 END), 0.00) AS total_assets,
        COALESCE(SUM(CASE WHEN type = 'liability' THEN credit_net ELSE 0.00 END), 0.00) AS total_liabilities,
        COALESCE(SUM(CASE WHEN type = 'equity' THEN credit_net ELSE 0.00 END), 0.00) AS total_equity,
        COALESCE(SUM(CASE WHEN type = 'revenue' THEN credit_net ELSE 0.00 END), 0.00) AS total_revenue,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN debit_net ELSE 0.00 END), 0.00) AS total_expense,
        COALESCE(SUM(debit_net), 0.00) AS ledger_delta
    FROM account_nets
)
SELECT cs.currency_count, cs.actual_currency, ji.unbalanced_journal_count,
    an.id AS account_id, an.code, an.name, an.type, an.is_active,
    an.debit_net, an.credit_net, st.total_assets, st.total_liabilities, st.total_equity,
    st.total_revenue, st.total_expense, st.ledger_delta,
    st.total_revenue - st.total_expense AS unclosed_profit_loss,
    st.total_liabilities + st.total_equity + (st.total_revenue - st.total_expense)
        AS liabilities_equity_and_unclosed_profit_loss,
    st.total_assets - st.total_liabilities - st.total_equity
        - (st.total_revenue - st.total_expense) AS equation_difference
FROM currency_summary cs
CROSS JOIN journal_integrity ji
CROSS JOIN statement_totals st
LEFT JOIN account_nets an ON TRUE
ORDER BY an.code, an.id
SQL, $bindings);

        $first = $rows[0];
        $currency = ReportingCurrency::resolve((int) $first->currency_count, $first->actual_currency);
        if ((int) $first->unbalanced_journal_count > 0
            || $first->ledger_delta !== '0.00' || $first->equation_difference !== '0.00') {
            throw new AccountingConflict('Financial statement cannot be generated from an out-of-balance posted ledger.');
        }

        return ['currency' => $currency, 'rows' => $rows, 'totals' => $first];
    }
}
