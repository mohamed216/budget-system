<?php

namespace App\Accounting;

use InvalidArgumentException;

final class OpeningBalanceTotals
{
    /** @param array<int, array{debit: string, credit: string}> $lines */
    public static function assertBalanced(array $lines): DecimalAmount
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Opening balances require at least two lines.');
        }

        $debitTotal = DecimalAmount::fromString('0');
        $creditTotal = DecimalAmount::fromString('0');
        foreach ($lines as $line) {
            $debit = DecimalAmount::fromString($line['debit']);
            $credit = DecimalAmount::fromString($line['credit']);
            if ($debit->isPositive() === $credit->isPositive()) {
                throw new InvalidArgumentException('Each opening balance line must have exactly one positive side.');
            }
            $debitTotal = $debitTotal->add($debit);
            $creditTotal = $creditTotal->add($credit);
        }

        if (! $debitTotal->isPositive() || ! $debitTotal->equals($creditTotal)) {
            throw new InvalidArgumentException('Opening balances require equal positive debit and credit totals.');
        }

        return $debitTotal;
    }
}
