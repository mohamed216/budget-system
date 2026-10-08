<?php

namespace App\Http\Requests\Accounting;

class FiscalYearClosePageRequest extends FiscalYearCloseRequest
{
    public function messages(): array
    {
        return [
            'start_date.required' => 'تاريخ بداية السنة المالية مطلوب.',
            'start_date.date_format' => 'أدخل تاريخ البداية بصيغة YYYY-MM-DD دون مسافات.',
            'end_date.required' => 'تاريخ نهاية السنة المالية مطلوب.',
            'end_date.date_format' => 'أدخل تاريخ النهاية بصيغة YYYY-MM-DD دون مسافات.',
            'end_date.after_or_equal' => 'يجب أن يكون تاريخ النهاية في يوم البداية أو بعده.',
            'currency.required' => 'العملة مطلوبة.',
            'currency.string' => 'العملة غير صالحة.',
            'currency.regex' => 'أدخل رمز العملة المعتمدة دون مسافات.',
            'currency.in' => 'العملة لا تطابق عملة المحاسبة المعتمدة.',
            'retained_earnings_account_id.required' => 'اختر حساب الأرباح المحتجزة.',
            'retained_earnings_account_id.integer' => 'اختر حساب أرباح محتجزة صالحاً دون مسافات.',
            'retained_earnings_account_id.min' => 'اختر حساب أرباح محتجزة صالحاً.',
            'id.missing' => 'لا يمكن تحديد رقم الإقفال يدوياً.',
            'user_id.missing' => 'لا يمكن تغيير مالك الإقفال.',
            'status.missing' => 'لا يمكن تغيير حالة الإقفال.',
            'journal_entry_id.missing' => 'لا يمكن تعيين القيد المرتبط يدوياً.',
            'closed_at.missing' => 'لا يمكن تعيين تاريخ الإقفال يدوياً.',
            'created_at.missing' => 'لا يمكن تغيير بيانات التدقيق.',
            'updated_at.missing' => 'لا يمكن تغيير بيانات التدقيق.',
        ];
    }
}
