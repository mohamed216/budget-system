<?php

namespace App\Accounting;

use App\Accounting\Exceptions\AccountingConflict;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class FiscalYearProfitLoss
{
    /** Return nonzero closing lines for revenue and expense accounts, followed by retained earnings. */
    public function closingLines(Collection $lines, Collection $accounts, int $retainedAccountId): array
    {
        $zero = DecimalAmount::fromString('0.00');
        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->id] = ['debit' => $zero, 'credit' => $zero];
        }
        foreach ($lines as $line) {
            $id = $line->chart_account_id;
            if (! isset($balances[$id])) {
                continue;
            }
            $balances[$id]['debit'] = $balances[$id]['debit']->add(DecimalAmount::fromString($line->getRawOriginal('debit')));
            $balances[$id]['credit'] = $balances[$id]['credit']->add(DecimalAmount::fromString($line->getRawOriginal('credit')));
        }

        $closing = [];
        $totalDebit = $zero;
        $totalCredit = $zero;
        foreach ($accounts as $account) {
            ['debit' => $debit, 'credit' => $credit] = $balances[$account->id];
            if ($debit->equals($credit)) {
                continue;
            }
            $amount = DecimalAmount::fromString(self::difference(
                $debit->compare($credit) > 0 ? $debit : $credit,
                $debit->compare($credit) > 0 ? $credit : $debit,
            ));
            if ($debit->compare($credit) > 0) {
                $closing[] = ['chart_account_id' => $account->id, 'debit' => '0.00', 'credit' => $amount->toDecimal()];
                $totalCredit = $totalCredit->add($amount);
            } else {
                $closing[] = ['chart_account_id' => $account->id, 'debit' => $amount->toDecimal(), 'credit' => '0.00'];
                $totalDebit = $totalDebit->add($amount);
            }
        }

        if (! $totalDebit->equals($totalCredit)) {
            $debitIsGreater = $totalDebit->compare($totalCredit) > 0;
            $amount = DecimalAmount::fromString(self::difference(
                $debitIsGreater ? $totalDebit : $totalCredit,
                $debitIsGreater ? $totalCredit : $totalDebit,
            ))->toDecimal();
            $closing[] = ['chart_account_id' => $retainedAccountId,
                'debit' => $debitIsGreater ? '0.00' : $amount,
                'credit' => $debitIsGreater ? $amount : '0.00'];
        }

        if (count($closing) === 1 || count($closing) > 65535) {
            throw new AccountingConflict('Fiscal-year close cannot produce a valid posted journal.');
        }

        return $closing;
    }

    /** Exact nonnegative subtraction of two aggregate DECIMAL values; greater >= lesser. */
    private static function difference(DecimalAmount $greater, DecimalAmount $lesser): string
    {
        $left = str_replace('.', '', $greater->toDecimal());
        $right = str_pad(str_replace('.', '', $lesser->toDecimal()), strlen($left), '0', STR_PAD_LEFT);
        $borrow = 0;
        $digits = '';
        for ($index = strlen($left) - 1; $index >= 0; $index--) {
            $digit = ord($left[$index]) - ord($right[$index]) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $digits .= (string) ($digit < 0 ? $digit + 10 : $digit);
        }
        if ($borrow !== 0) {
            throw new InvalidArgumentException('Invalid fiscal-year decimal subtraction.');
        }
        $minor = ltrim(strrev($digits), '0');
        $minor = $minor === '' ? '0' : $minor;
        $padded = str_pad($minor, 3, '0', STR_PAD_LEFT);

        return substr($padded, 0, -2).'.'.substr($padded, -2);
    }
}
