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
    case PostedLedgerCurrencyMismatch;
    case PostedLedgerUnbalanced;
    case AccountingPeriodOverlap;
    case AccountingPeriodState;
    case JournalDraftStale;
    case JournalReversalIneligible;
    case CashRoleFrozen;
    case CashFlowAlreadyCompleted;
    case CashFlowUnreviewedAccount;
    case CashFlowNoCashLines;
    case CashFlowInvalidAllocation;
    case CashFlowJournalNotPosted;
    case CashFlowSpecialJournal;
    case CashFlowReversalRequiresInheritance;
    case CashFlowClassificationIncomplete;
    case CashFlowCorruptData;
}
