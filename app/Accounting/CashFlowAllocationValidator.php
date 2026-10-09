<?php

namespace App\Accounting;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CashFlowAllocationValidator
{
    /** @return list<HistoricalCashFlowAllocationInput> */
    public function fromPersisted(Collection $rows): array
    {
        $allocations = [];
        foreach ($rows as $index => $row) {
            try {
                $allocations[] = HistoricalCashFlowAllocationInput::fromArray([
                    'debit_line_id' => (int) $row->debit_line_id,
                    'credit_line_id' => (int) $row->credit_line_id,
                    'amount' => $row->amount, 'category' => $row->category,
                ], $index);
            } catch (ValidationException) {
                $this->invalid('Persisted cash-flow allocation is invalid.');
            }
        }

        return $allocations;
    }

    /**
     * Validate debit-to-credit cash allocation invariants. Drafts permit incomplete coverage.
     * @param list<HistoricalCashFlowAllocationInput> $allocations
     */
    public function validate(Collection $lines, Collection $accounts, array $allocations, bool $complete): bool
    {
        $accountsById = $accounts->keyBy('id');
        $facts = [];
        $cashLineIds = [];
        foreach ($lines as $line) {
            $account = $accountsById->get($line->chart_account_id);
            if ($account === null) {
                $this->invalid('Journal references an unavailable account.');
            }
            $role = CashAccountRole::tryFrom((string) ($account->cash_role ?? ''));
            if ($complete && $role === null) {
                $this->unreviewed();
            }
            try {
                $debit = DecimalAmount::fromString($line->getRawOriginal('debit'));
                $credit = DecimalAmount::fromString($line->getRawOriginal('credit'));
            } catch (InvalidArgumentException) {
                $this->invalid('Journal line contains invalid money.');
            }
            if ($debit->isPositive() === $credit->isPositive()) {
                $this->invalid('Journal line must have one positive side.');
            }
            $facts[$line->id] = [
                'side' => $debit->isPositive() ? 'debit' : 'credit',
                'amount' => $debit->isPositive() ? $debit : $credit,
                'cash' => $role?->isCash(),
            ];
            if ($role?->isCash()) {
                $cashLineIds[] = $line->id;
            }
        }

        $covered = [];
        foreach ($allocations as $row) {
            $debit = $facts[$row->debitLineId] ?? null;
            $credit = $facts[$row->creditLineId] ?? null;
            if ($debit === null || $credit === null || $row->debitLineId === $row->creditLineId
                || $debit['side'] !== 'debit' || $credit['side'] !== 'credit') {
                $this->invalid('Allocation references invalid journal line sides.');
            }
            if ($debit['cash'] === null || $credit['cash'] === null) {
                $this->unreviewed();
            }
            if ((! $debit['cash'] && ! $credit['cash'])
                || (($debit['cash'] xor $credit['cash']) !== ($row->category !== null))) {
                $this->invalid('Allocation cash roles and category do not match.');
            }
            foreach ([$row->debitLineId, $row->creditLineId] as $lineId) {
                $covered[$lineId] = ($covered[$lineId] ?? DecimalAmount::fromString('0'))->add($row->amount);
                if ($covered[$lineId]->compare($facts[$lineId]['amount']) > 0) {
                    $this->invalid('Allocation exceeds a journal line.');
                }
            }
        }
        if ($complete) {
            foreach ($cashLineIds as $lineId) {
                if (! isset($covered[$lineId]) || ! $covered[$lineId]->equals($facts[$lineId]['amount'])) {
                    $this->invalid('Every cash line must be fully allocated.');
                }
            }
            if ($cashLineIds === [] && $allocations !== []) {
                $this->invalid('Journal has no cash lines to allocate.');
            }
        }

        return $cashLineIds !== [];
    }

    private function unreviewed(): never
    {
        throw new AccountingConflict('Every referenced account needs a reviewed cash role.',
            reason: AccountingConflictReason::CashFlowUnreviewedAccount);
    }

    private function invalid(string $message): never
    {
        throw new AccountingConflict($message, reason: AccountingConflictReason::CashFlowInvalidAllocation);
    }
}
