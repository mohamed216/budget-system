<?php

namespace App\Accounting;

use App\Accounting\Exceptions\AccountingConflict;
use Illuminate\Support\Collection;

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
            $debitIsGreater = $debit->compare($credit) > 0;
            $amount = DecimalAmount::fromString(($debitIsGreater ? $debit : $credit)
                ->subtract($debitIsGreater ? $credit : $debit)->toDecimal());
            if ($debitIsGreater) {
                $closing[] = ['chart_account_id' => $account->id, 'debit' => '0.00', 'credit' => $amount->toDecimal()];
                $totalCredit = $totalCredit->add($amount);
            } else {
                $closing[] = ['chart_account_id' => $account->id, 'debit' => $amount->toDecimal(), 'credit' => '0.00'];
                $totalDebit = $totalDebit->add($amount);
            }
        }

        if (! $totalDebit->equals($totalCredit)) {
            $debitIsGreater = $totalDebit->compare($totalCredit) > 0;
            $amount = DecimalAmount::fromString(($debitIsGreater ? $totalDebit : $totalCredit)
                ->subtract($debitIsGreater ? $totalCredit : $totalDebit)->toDecimal())->toDecimal();
            $closing[] = ['chart_account_id' => $retainedAccountId,
                'debit' => $debitIsGreater ? '0.00' : $amount,
                'credit' => $debitIsGreater ? $amount : '0.00'];
        }

        if (count($closing) === 1 || count($closing) > 65535) {
            throw new AccountingConflict('Fiscal-year close cannot produce a valid posted journal.');
        }

        return $closing;
    }
}
