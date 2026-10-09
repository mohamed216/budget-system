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

    public function test_page_mutation_messages_use_reasons_and_unknown_conflicts_have_a_safe_fallback(): void
    {
        $presenter = new AccountingConflictPresentation;
        $raw = 'SQLSTATE[HY000] private English domain detail';

        $this->assertSame('تتداخل الفترة المحاسبية مع فترة موجودة.',
            $presenter->pageMutation(new AccountingConflict($raw, reason: AccountingConflictReason::AccountingPeriodOverlap)));
        $this->assertSame('لا يمكن تنفيذ العملية على هذه الفترة المحاسبية في حالتها الحالية.',
            $presenter->pageMutation(new AccountingConflict($raw, reason: AccountingConflictReason::AccountingPeriodState)));
        $this->assertSame('تغيرت نسخة مسودة القيد. أعد تحميلها قبل الحفظ.',
            $presenter->pageMutation(new AccountingConflict($raw, reason: AccountingConflictReason::JournalDraftStale)));
        $this->assertSame('لا يمكن عكس هذا القيد؛ يجب أن يكون قيداً أصلياً مرحلاً ولم يُعكس من قبل.',
            $presenter->pageMutation(new AccountingConflict($raw, reason: AccountingConflictReason::JournalReversalIneligible)));
        $this->assertSame('تعذر إتمام العملية المحاسبية.',
            $presenter->pageMutation(new AccountingConflict($raw)));
    }

    public function test_ledger_report_conflicts_use_reasons_and_keep_unrelated_reasons_distinct(): void
    {
        $presenter = new AccountingConflictPresentation;
        $domainText = 'SQLSTATE[HY000] private English detail';

        $this->assertSame('تعذر عرض ميزان المراجعة بسبب اختلاف عملات القيود المرحلة.',
            $presenter->trialBalance(new AccountingConflict($domainText, reason: AccountingConflictReason::PostedLedgerCurrencyMismatch)));
        $this->assertSame('تعذر عرض ميزان المراجعة لأن القيود المرحلة غير متوازنة.',
            $presenter->trialBalance(new AccountingConflict($domainText, reason: AccountingConflictReason::PostedLedgerUnbalanced)));
        $this->assertSame('تعذر عرض ميزان المراجعة بسبب تعارض في بيانات التقرير.',
            $presenter->trialBalance(new AccountingConflict($domainText, reason: AccountingConflictReason::AccountingPeriodClosed)));
        $this->assertSame('تعذر عرض الأستاذ بسبب اختلاف عملات القيود المرحلة أو عدم توازنها.',
            $presenter->generalLedger(new AccountingConflict($domainText, reason: AccountingConflictReason::PostedLedgerUnbalanced)));
    }
}
