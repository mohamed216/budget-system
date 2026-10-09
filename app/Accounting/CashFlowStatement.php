<?php

namespace App\Accounting;

final readonly class CashFlowStatement
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $currency,
        public bool $hasDesignatedCashAccounts,
        public SignedDecimalAmount $beginningCash,
        public SignedDecimalAmount $openingBalanceAdjustments,
        public SignedDecimalAmount $operatingActivities,
        public SignedDecimalAmount $investingActivities,
        public SignedDecimalAmount $financingActivities,
        public SignedDecimalAmount $netCashFlow,
        public SignedDecimalAmount $netChangeInCash,
        public SignedDecimalAmount $endingCash,
    ) {}

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate, 'end_date' => $this->endDate,
            'currency' => $this->currency,
            'has_designated_cash_accounts' => $this->hasDesignatedCashAccounts,
            'beginning_cash' => $this->beginningCash->toDecimal(),
            'opening_balance_adjustments' => $this->openingBalanceAdjustments->toDecimal(),
            'operating_activities' => $this->operatingActivities->toDecimal(),
            'investing_activities' => $this->investingActivities->toDecimal(),
            'financing_activities' => $this->financingActivities->toDecimal(),
            'net_cash_flow' => $this->netCashFlow->toDecimal(),
            'net_change_in_cash' => $this->netChangeInCash->toDecimal(),
            'ending_cash' => $this->endingCash->toDecimal(),
        ];
    }
}
