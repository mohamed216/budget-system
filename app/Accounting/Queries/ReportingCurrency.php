<?php

namespace App\Accounting\Queries;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;

final class ReportingCurrency
{
    /** Resolve the currency summary from the same SQL snapshot as the report rows. */
    public static function resolve(int $distinctCount, ?string $actualCurrency): string
    {
        if ($distinctCount > 1) {
            throw new AccountingConflict('Financial report cannot combine journals with different currencies.',
                reason: AccountingConflictReason::PostedLedgerCurrencyMismatch);
        }

        if ($distinctCount === 0) {
            return config('accounting.currency');
        }

        if ($distinctCount !== 1 || $actualCurrency === null) {
            throw new AccountingConflict('Financial report currency summary is invalid.');
        }

        return $actualCurrency;
    }
}
