<?php

namespace App\OperationalAccounting\Actions;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\User;
use App\OperationalAccounting\OperationalReconciliationSnapshot;
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
        $snapshot = (new OperationalReconciliationSnapshot)->load($ownerId, $reference);
        $review = (object) $snapshot['review'];
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
