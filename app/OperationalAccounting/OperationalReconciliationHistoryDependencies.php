<?php

namespace App\OperationalAccounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use Illuminate\Support\Facades\DB;

/** Evidence-stage reads only; the action already holds owner, gate and source locks. */
final class OperationalReconciliationHistoryDependencies
{
    /**
     * The manifest describes the mutation's locked headers, charts and complete lines,
     * not an advisory report. No missing live source is fetched here.
     *
     * @param  array{journal_ids: array, chart_ids: array, lines: array}  $lockedSourceManifest
     */
    public function lockBoundReview(int $ownerId, ?object $lockedIntegration, array $lockedSourceManifest): ?array
    {
        foreach (['lines', 'journal_ids', 'chart_ids'] as $key) {
            if (! isset($lockedSourceManifest[$key]) || ! is_array($lockedSourceManifest[$key])) {
                throw new AccountingConflict('History source manifest is incomplete; rediscover the entire transaction.',
                    reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
        }
        $journalIds = array_map('strval', $lockedSourceManifest['journal_ids']);
        $chartIds = array_map('strval', $lockedSourceManifest['chart_ids']);
        foreach ($lockedSourceManifest['lines'] as $line) {
            if (! isset($line['user_id'], $line['journal_entry_id'], $line['chart_account_id'])
                || (string) $line['user_id'] !== (string) $ownerId
                || ! in_array((string) $line['journal_entry_id'], $journalIds, true)
                || ! in_array((string) $line['chart_account_id'], $chartIds, true)) {
                throw new AccountingConflict('History source manifest is incomplete; rediscover the entire transaction.',
                    reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
        }
        if ($lockedIntegration === null) {
            return null;
        }
        if (($lockedIntegration->reconciliation_reference === null) !== ($lockedIntegration->reconciled_at === null)) {
            throw new AccountingConflict('Reconciliation evidence must be a complete pair.', reason: AccountingConflictReason::ReconciliationState);
        }
        if ($lockedIntegration->reconciliation_reference === null) {
            return null;
        }
        // Identity discovery only. A stale consistent snapshot cannot authorize a
        // negative answer: restart from a fresh transaction if discovery misses.
        $candidate = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
            ->where('reference', $lockedIntegration->reconciliation_reference)->first(['id']);
        if ($candidate === null) {
            throw new AccountingConflict('Bound review identity requires rediscovery.', reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
        }
        $charts = DB::table('operational_reconciliation_charts')->where('user_id', $ownerId)
            ->where('review_id', $candidate->id)->orderBy('chart_account_id')->sharedLock()->pluck('chart_account_id')->all();
        $members = DB::table('operational_reconciliation_journals')->where('user_id', $ownerId)
            ->where('review_id', $candidate->id)->orderBy('journal_entry_id')->sharedLock()->pluck('journal_entry_id')->all();
        // 000015 source/child locks precede the review parent. Implicit FK checks
        // may wait; no trigger NOWAIT guarantee is assumed by this protocol.
        $review = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
            ->where('id', $candidate->id)->sharedLock()->first();
        if ($review === null || $review->reference !== $lockedIntegration->reconciliation_reference
            || $review->reviewed_by === null || $review->completed_at === null
            || $review->completed_at !== $lockedIntegration->reconciled_at
            || $review->historical_transaction_strategy !== $lockedIntegration->historical_transaction_strategy
            || $review->accounting_currency !== $lockedIntegration->accounting_currency
            || (string) $review->transaction_high_water_id !== (string) $lockedIntegration->transaction_high_water_id
            || $review->scope_reviewed_at !== $lockedIntegration->scope_reviewed_at) {
            throw new AccountingConflict('History mutation requires a valid completed reconciliation binding.',
                reason: AccountingConflictReason::ReconciliationState);
        }

        return ['review_id' => $review->id, 'chart_ids' => $charts, 'journal_ids' => $members];
    }
}
