<?php

namespace App\Accounting;

enum CashAccountRole: string
{
    case NonCash = 'non_cash';
    case Cash = 'cash';
    case CashEquivalent = 'cash_equivalent';

    public function isCash(): bool
    {
        return $this !== self::NonCash;
    }
}
