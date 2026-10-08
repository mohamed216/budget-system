<?php

namespace Tests\Unit;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Http\Presenters\AccountingConflictPresentation;
use PHPUnit\Framework\TestCase;

class AccountingConflictPresentationTest extends TestCase
{
    public function test_opening_balance_conflicts_use_stable_reasons_not_message_text(): void
    {
        $presenter = new AccountingConflictPresentation;
        $period = new AccountingConflict('An unrelated English message.', reason: AccountingConflictReason::AccountingPeriodClosed);
        $fiscal = new AccountingConflict('An unrelated English message.', reason: AccountingConflictReason::FiscalYearClosed);
        $posted = new AccountingConflict('An unrelated English message.', reason: AccountingConflictReason::OpeningBalancePosted);

        $this->assertSame('تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.', $presenter->openingBalanceDraft($period));
        $this->assertSame('تاريخ الافتتاح يقع ضمن سنة مالية مقفلة نهائياً.', $presenter->openingBalanceDraft($fiscal));
        $this->assertSame('لا يمكن تعديل أرصدة افتتاحية تم ترحيلها.', $presenter->openingBalanceDraft($posted));
        $this->assertSame('تاريخ الافتتاح يقع ضمن سنة مالية مقفلة نهائياً.', $presenter->openingBalancePost($fiscal));
    }

    public function test_unknown_conflicts_use_safe_contextual_fallbacks(): void
    {
        $presenter = new AccountingConflictPresentation;
        $unknown = new AccountingConflict('SQLSTATE[HY000] /secret/path');

        foreach ([$presenter->openingBalanceDraft($unknown), $presenter->openingBalancePost($unknown), $presenter->fiscalYearClose($unknown)] as $message) {
            $this->assertNotSame('', $message);
            $this->assertStringNotContainsString('SQLSTATE', $message);
            $this->assertStringNotContainsString('/secret/path', $message);
        }
    }

    public function test_fiscal_close_presentations_use_reasons_not_english_messages(): void
    {
        $presenter = new AccountingConflictPresentation;
        foreach ([
            [AccountingConflictReason::FiscalYearOverlap, 'تتداخل الفترة المختارة'],
            [AccountingConflictReason::FiscalYearDraftJournals, 'مسودات القيود اليومية'],
            [AccountingConflictReason::FiscalYearDraftOpeningBalances, 'مسودات الأرصدة الافتتاحية'],
            [AccountingConflictReason::FiscalYearRetainedEarningsAccount, 'حساب أرباح محتجزة نشطاً'],
            [AccountingConflictReason::FiscalYearCurrencyMismatch, 'عملة أحد القيود المرحلة'],
        ] as [$reason, $message]) {
            $this->assertStringContainsString($message,
                $presenter->fiscalYearClose(new AccountingConflict('Unrelated English message', reason: $reason)));
        }
    }

    public function test_json_exception_message_remains_the_original_text(): void
    {
        $conflict = new AccountingConflict('Fiscal year for the accounting date is permanently closed.',
            reason: AccountingConflictReason::FiscalYearClosed);

        $this->assertSame('Fiscal year for the accounting date is permanently closed.', $conflict->getMessage());
    }
}
