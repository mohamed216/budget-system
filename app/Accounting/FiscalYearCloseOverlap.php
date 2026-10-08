<?php

namespace App\Accounting;

use App\Models\FiscalYearClose;
use App\Models\User;

final class FiscalYearCloseOverlap
{
    /** Caller must coordinate fiscal-year-close writes under the owner's transaction lock. */
    public function exists(User $owner, string $startDate, string $endDate, ?int $excludeId = null, bool $lockForUpdate = false): bool
    {
        $query = FiscalYearClose::ownedBy($owner)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($excludeId !== null) {
            $query->whereKeyNot($excludeId);
        }
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first(['id']) !== null;
    }
}
