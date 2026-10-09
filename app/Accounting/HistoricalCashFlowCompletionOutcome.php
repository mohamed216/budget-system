<?php

namespace App\Accounting;

enum HistoricalCashFlowCompletionOutcome
{
    case Completed;
    case NoCashLines;
}
