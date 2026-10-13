<?php

namespace App\OperationalAccounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/** Called inside the caller's outer transaction AFTER its owner lock. */
final class OperationalReconciliationSnapshot
{
    public function load(int|string $ownerId, ?string $reference = null): array
    {
        // Candidate reads discover identity only. They are NOT authority, even
        // when an earlier consistent read has established a repeatable-read view.
        $candidate = $reference === null ? null : $this->candidate($ownerId, $reference);
        $snapshot = ['owner_id' => $ownerId, 'currency' => config('accounting.currency'), 'sources' => [], 'evidence' => []];

        // Deliberately owner-wide source closure for this infrequent cutover action:
        // avoids selecting participants by usage and avoids acquiring missed source
        // locks backwards. Membership/participation are still validated ONLY from
        // explicit scope and reviewed targets. Every read below is a CURRENT read.
        // SHARE locks keep trigger source reads compatible; owner X serializes app
        // writers. Implicit FK/DML locks can still deadlock: retry the whole action.
        // No new accounting date is posted: open-period guards are not applicable.
        // Fiscal-close rows remain relevant immutable provenance/guard sources.
        $tables = [
            'fiscal_year_closes' => 'id',
            'accounts' => 'id', 'categories' => 'id',
            'operational_accounting_integrations' => 'user_id',
            'operational_accounting_account_scope' => 'account_id',
            'operational_accounting_category_scope' => 'category_id',
            'operational_account_mappings' => 'account_id', 'operational_category_mappings' => 'category_id',
            'chart_of_accounts' => 'id', 'transactions' => 'id', 'journal_entries' => 'id', 'journal_lines' => 'id',
            'journal_line_allocations' => 'id', 'cash_flow_journal_completions' => 'id',
            'operational_transaction_journals' => 'transaction_id',
            'opening_balance_batches' => 'id', 'opening_balance_lines' => 'id',
        ];
        foreach ($tables as $table => $key) {
            $query = DB::table($table)->where('user_id', $ownerId)->orderBy($key);
            $query = $table === 'operational_accounting_integrations' ? $query->lockForUpdate() : $query->sharedLock();
            $snapshot['sources'][$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }
        $snapshot['gate'] = $snapshot['sources']['operational_accounting_integrations'][0] ?? null;
        if ($reference === null) {
            $gate = $snapshot['gate'];
            if ($gate === null || $gate['state'] !== 'reconciling'
                || $gate['reconciliation_reference'] === null || $gate['reconciled_at'] === null) {
                throw new AccountingConflict('READY requires a bound reconciling integration.', reason: AccountingConflictReason::ReconciliationState);
            }
            $candidate = $this->candidate($ownerId, $gate['reconciliation_reference']);
        }

        // These child rows are sources for other 000015 guards. Protect complete
        // review-key ranges BEFORE parent X, not just the rows in a preview list.
        foreach (['charts' => 'chart_account_id', 'accounts' => 'account_id', 'categories' => 'category_id',
            'journals' => 'journal_entry_id', 'attributions' => 'id'] as $suffix => $key) {
            $snapshot['evidence'][$suffix] = DB::table('operational_reconciliation_'.$suffix)
                ->where('review_id', $candidate->id)->where('user_id', $ownerId)->orderBy($key)->sharedLock()
                ->get()->map(fn ($row) => (array) $row)->all();
        }
        $snapshot['predecessor'] = null;
        if ($candidate->supersedes_review_id !== null) {
            $predecessor = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
                ->where('id', $candidate->supersedes_review_id)->sharedLock()->first();
            $snapshot['predecessor'] = $predecessor === null ? null : (array) $predecessor;
        }
        $review = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
            ->where('id', $candidate->id)->lockForUpdate()->firstOrFail();
        if ((string) $review->supersedes_review_id !== (string) $candidate->supersedes_review_id) {
            throw new AccountingConflict('Review source set changed; rediscovery required.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
        }
        $snapshot['review'] = (array) $review;

        return $snapshot;
    }

    private function candidate(int|string $ownerId, string $reference): object
    {
        $candidate = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
            ->where('reference', $reference)->first(['id', 'supersedes_review_id']);
        if ($candidate === null) {
            throw (new ModelNotFoundException)->setModel('operational_reconciliation_reviews');
        }

        return $candidate;
    }
}
