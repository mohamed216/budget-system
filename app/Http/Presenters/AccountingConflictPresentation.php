<?php

namespace App\Http\Presenters;

use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;

final class AccountingConflictPresentation
{
    public const OPENING_BALANCE_POSTED = 'لا يمكن تعديل أرصدة افتتاحية تم ترحيلها.';

    public function pageMutation(AccountingConflict $conflict): string
    {
        return match ($conflict->reason) {
            AccountingConflictReason::AccountingPeriodOverlap => 'تتداخل الفترة المحاسبية مع فترة موجودة.',
            AccountingConflictReason::AccountingPeriodState => 'لا يمكن تنفيذ العملية على هذه الفترة المحاسبية في حالتها الحالية.',
            AccountingConflictReason::JournalDraftStale => 'تغيرت نسخة مسودة القيد. أعد تحميلها قبل الحفظ.',
            AccountingConflictReason::JournalReversalIneligible => 'لا يمكن عكس هذا القيد؛ يجب أن يكون قيداً أصلياً مرحلاً ولم يُعكس من قبل.',
            default => 'تعذر إتمام العملية المحاسبية.',
        };
    }

    public function generalLedger(AccountingConflict $conflict): string
    {
        return 'تعذر عرض الأستاذ بسبب اختلاف عملات القيود المرحلة أو عدم توازنها.';
    }

    public function trialBalance(AccountingConflict $conflict): string
    {
        return match ($conflict->reason) {
            AccountingConflictReason::PostedLedgerCurrencyMismatch => 'تعذر عرض ميزان المراجعة بسبب اختلاف عملات القيود المرحلة.',
            AccountingConflictReason::PostedLedgerUnbalanced => 'تعذر عرض ميزان المراجعة لأن القيود المرحلة غير متوازنة.',
            default => 'تعذر عرض ميزان المراجعة بسبب تعارض في بيانات التقرير.',
        };
    }

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
