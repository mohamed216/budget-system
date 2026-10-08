<?php

namespace App\Accounting\Exceptions;

enum AccountingConflictReason
{
    case AccountingPeriodClosed;
    case FiscalYearClosed;
    case OpeningBalancePosted;
    case FiscalYearOverlap;
    case FiscalYearDraftJournals;
    case FiscalYearDraftOpeningBalances;
    case FiscalYearRetainedEarningsAccount;
    case FiscalYearCurrencyMismatch;
}
