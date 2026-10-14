<?php

namespace App\OperationalAccounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use Illuminate\Support\Facades\DB;

/** Current dependency loading only. The action must hold owner first and choose each lock stage. */
final class OperationalReconciliationChartDependencies
{
    /** Integration stage, before charts: return chart IDs selected by explicit scopes only. */
    public function lockParticipation(int $ownerId): array
    {
        return $this->lockParticipationWithAccountTargets($ownerId)['chart_targets'];
    }

    /**
     * Lock the same ordered participation rows once, retaining account-only authority.
     *
     * @return array{chart_targets: array<int, true>, account_chart_targets: array<int, true>}
     */
    public function lockParticipationWithAccountTargets(int $ownerId): array
    {
        $scopes = [];
        foreach (['account', 'category'] as $kind) {
            $scopes[$kind] = DB::table('operational_accounting_'.$kind.'_scope')->where('user_id', $ownerId)
                ->orderBy($kind.'_id')->sharedLock()->pluck($kind.'_id')->mapWithKeys(fn ($id) => [$id => true])->all();
        }
        $targets = ['account' => [], 'category' => []];
        foreach (['account', 'category'] as $kind) {
            $mappings = DB::table('operational_'.$kind.'_mappings')->where('user_id', $ownerId)
                ->orderBy($kind.'_id')->sharedLock()->get([$kind.'_id', 'chart_account_id']);
            foreach ($mappings as $mapping) {
                if (isset($scopes[$kind][$mapping->{$kind.'_id'}])) {
                    $targets[$kind][$mapping->chart_account_id] = true;
                }
            }
        }

        return [
            'chart_targets' => $targets['account'] + $targets['category'],
            'account_chart_targets' => $targets['account'],
        ];
    }

    /** After charts: headers before lines. No snapshot-based candidate/negative-exists decisions. */
    public function lockJournalUse(int $ownerId, int $chartId): array
    {
        // Identity/status projection closes the owner header set without loading a
        // reconciliation snapshot. Supported writers are serialized by owner X.
        $headers = DB::table('journal_entries')->where('user_id', $ownerId)->orderBy('id')
            ->sharedLock()->get(['id', 'status'])->keyBy('id');
        $lines = DB::table('journal_lines')->where('user_id', $ownerId)->where('chart_account_id', $chartId)
            ->orderBy('id')->sharedLock()->get(['id', 'journal_entry_id']);
        $posted = [];
        foreach ($lines as $line) {
            $header = $headers->get($line->journal_entry_id);
            if ($header === null) {
                throw new AccountingConflict('Journal dependency set changed; restart the entire mutation.',
                    reason: AccountingConflictReason::ReconciliationDiscoveryConflict);
            }
            if ($header->status === 'posted') {
                $posted[$header->id] = $header->id;
            }
        }

        return ['referenced' => $lines->isNotEmpty(), 'posted_journal_ids' => array_values($posted)];
    }

    /** Evidence stage after locked journals/lines: membership sources before review parent. */
    public function lockBoundJournalDependency(int $ownerId, ?object $integration, array $postedJournalIds): bool
    {
        if ($integration === null || $integration->reconciliation_reference === null || $postedJournalIds === []) {
            return false;
        }
        $members = DB::table('operational_reconciliation_journals')->where('user_id', $ownerId)
            ->whereIn('journal_entry_id', $postedJournalIds)->orderBy('review_id')->orderBy('journal_entry_id')
            ->sharedLock()->get(['review_id']);
        $review = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
            ->where('reference', $integration->reconciliation_reference)->sharedLock()->first();
        if ($review === null || $review->reference !== $integration->reconciliation_reference
            || $review->reviewed_by === null || $review->completed_at === null
            || $review->completed_at !== $integration->reconciled_at
            || $review->historical_transaction_strategy !== $integration->historical_transaction_strategy
            || $review->accounting_currency !== $integration->accounting_currency
            || (string) $review->transaction_high_water_id !== (string) $integration->transaction_high_water_id
            || $review->scope_reviewed_at !== $integration->scope_reviewed_at) {
            throw new AccountingConflict('Chart dependency requires a valid completed reconciliation binding.',
                reason: AccountingConflictReason::ReconciliationState);
        }

        return $members->contains(fn ($member) => (string) $member->review_id === (string) $review->id);
    }
}
