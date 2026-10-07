<?php

namespace Tests\Unit;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Queries\ReportingCurrency;
use Tests\TestCase;

class ReportingCurrencyTest extends TestCase
{
    public function test_empty_scope_uses_configured_currency(): void
    {
        $this->assertSame(config('accounting.currency'), ReportingCurrency::resolve(0, null));
    }

    public function test_single_currency_uses_actual_historical_currency(): void
    {
        $this->assertSame('SAR', ReportingCurrency::resolve(1, 'SAR'));
    }

    public function test_mixed_currency_is_a_deterministic_accounting_conflict(): void
    {
        $this->expectException(AccountingConflict::class);
        $this->expectExceptionMessage('Financial report cannot combine journals with different currencies.');
        ReportingCurrency::resolve(2, 'SAR');
    }

    public function test_invalid_single_currency_summary_fails_closed(): void
    {
        $this->expectException(AccountingConflict::class);
        ReportingCurrency::resolve(1, null);
    }
}
