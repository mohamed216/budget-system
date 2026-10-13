<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompleteOperationalReconciliation
{
    public function __construct(private readonly OperationalReconciliationValidator $validator = new OperationalReconciliationValidator) {}

    /** @return array{review_id: int, reference: string, reviewed_by: int, completed_at: string, integration_state: string} */
    public function execute(User $owner, User $reviewer, string $reviewReference, string $expectedEvidenceDigest): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new AccountingConflict('Completion requires its own outer transaction.', reason: AccountingConflictReason::ReconciliationState);
        }
        foreach ([$owner, $reviewer] as $identity) {
            if (! $identity->exists || $identity->getKey() === null || $identity->getKey() <= 0) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }
        }
        if ((string) $owner->getKey() !== (string) $reviewer->getKey()) {
            throw new AuthorizationException('Only owner self-review is supported.');
        }
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $reviewReference)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $expectedEvidenceDigest)) {
            throw ValidationException::withMessages(['review' => 'Provide a canonical reference and SHA-256 evidence approval token.']);
        }

        // Only discovery instability gets this retry loop. Laravel's inner retry
        // covers deadlocks, always from the beginning of the outer transaction.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(fn () => $this->complete($owner, $reviewReference, $expectedEvidenceDigest), 3);
            } catch (AccountingConflict $exception) {
                if ($exception->reason !== AccountingConflictReason::ReconciliationDiscoveryConflict || $attempt === 2) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable completion retry state.');
    }

    private function complete(User $owner, string $reference, string $digest): array
    {
        AccountingPeriodLocks::owner($owner);
        $ownerId = $owner->getKey();
        // Candidate reads discover identity only. They are NOT authority, even
        // when an earlier consistent read has established a repeatable-read view.
        $candidate = DB::table('operational_reconciliation_reviews')->where('user_id', $ownerId)
            ->where('reference', $reference)->first();
        if ($candidate === null) {
            throw (new ModelNotFoundException)->setModel('operational_reconciliation_reviews');
        }
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
        // All source/child sets are already protected. Validator has no DB access
        // and cannot silently expand this manifest or acquire backwards locks.
        $this->validator->validate($snapshot, $reference, $digest);

        $completedAt = now('UTC')->format('Y-m-d H:i:s.u');
        if ($completedAt < $review->created_at) {
            throw new AccountingConflict('Completion time precedes review creation.', reason: AccountingConflictReason::ReconciliationEvidenceInvalid);
        }
        $updated = DB::table('operational_reconciliation_reviews')->where('id', $review->id)->where('user_id', $ownerId)
            ->whereNull('completed_at')->update(['reviewed_by' => $ownerId, 'completed_at' => $completedAt]);
        if ($updated !== 1) {
            throw new AccountingConflict('Review was already completed.', reason: AccountingConflictReason::ReconciliationAlreadyCompleted);
        }
        $sealed = DB::table('operational_reconciliation_reviews')->where('id', $review->id)->where('user_id', $ownerId)
            ->sharedLock()->firstOrFail();
        $bound = DB::table('operational_accounting_integrations')->where('user_id', $ownerId)->where('state', 'reconciling')
            ->whereNull('reconciliation_reference')->whereNull('reconciled_at')->update([
                'reconciliation_reference' => $sealed->reference, 'reconciled_at' => $sealed->completed_at,
            ]);
        if ($bound !== 1) {
            throw new AccountingConflict('Integration could not be bound.', reason: AccountingConflictReason::ReconciliationState);
        }
        $integration = DB::table('operational_accounting_integrations')->where('user_id', $ownerId)->sharedLock()->firstOrFail();

        return ['review_id' => (int) $sealed->id, 'reference' => $sealed->reference,
            'reviewed_by' => (int) $sealed->reviewed_by, 'completed_at' => $sealed->completed_at, 'integration_state' => $integration->state];
    }
}
