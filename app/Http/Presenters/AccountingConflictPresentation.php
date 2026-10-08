<?php

namespace App\Http\Presenters;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;

final class AccountingConflictPresentation
{
    public const OPENING_BALANCE_POSTED = 'لا يمكن تعديل أرصدة افتتاحية تم ترحيلها.';

    public function openingBalanceDraft(AccountingConflict $conflict): string
    {
        return $this->dateLockMessage($conflict) ?? match ($conflict->reason) {
            AccountingConflictReason::OpeningBalancePosted => self::OPENING_BALANCE_POSTED,
            default => 'تعذر حفظ الأرصدة الافتتاحية. تحقق من حالة الدفعة والبيانات المحاسبية.',
        };
    }

    public function openingBalancePost(AccountingConflict $conflict): string
    {
        return $this->dateLockMessage($conflict)
            ?? 'تعذر ترحيل الأرصدة الافتتاحية. تحقق من العملة والحسابات النشطة وتوازن السطور.';
    }

    public function fiscalYearClose(AccountingConflict $conflict): string
    {
        return match ($conflict->reason) {
            AccountingConflictReason::FiscalYearOverlap => 'تتداخل الفترة المختارة مع سنة مالية مقفلة مسبقاً.',
            AccountingConflictReason::FiscalYearDraftJournals => 'يجب معالجة مسودات القيود اليومية ضمن الفترة قبل الإقفال.',
            AccountingConflictReason::FiscalYearDraftOpeningBalances => 'يجب معالجة مسودات الأرصدة الافتتاحية ضمن الفترة قبل الإقفال.',
            AccountingConflictReason::FiscalYearRetainedEarningsAccount => 'اختر حساب أرباح محتجزة نشطاً من حقوق الملكية يخصك.',
            AccountingConflictReason::FiscalYearCurrencyMismatch => 'عملة أحد القيود المرحلة لا تطابق عملة السنة المالية.',
            default => 'تعذر إقفال السنة المالية. تحقق من القيود والحسابات وتوازن المبالغ.',
        };
    }

    private function dateLockMessage(AccountingConflict $conflict): ?string
    {
        return match ($conflict->reason) {
            AccountingConflictReason::AccountingPeriodClosed => 'تاريخ الافتتاح يقع ضمن فترة محاسبية مغلقة.',
            AccountingConflictReason::FiscalYearClosed => 'تاريخ الافتتاح يقع ضمن سنة مالية مقفلة نهائياً.',
            default => null,
        };
    }
}
