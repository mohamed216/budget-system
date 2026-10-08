<?php

namespace App\Http\Requests\Accounting;

use App\Rules\DecimalAmountRule;

class OpeningBalancePageRequest extends OpeningBalanceDraftRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->exists('lines')) {
            $this->merge(['lines' => []]);
        }
    }

    protected function moneyRule(): DecimalAmountRule
    {
        return new DecimalAmountRule('أدخل مبلغاً عشرياً نصياً صحيحاً بحد أقصى منزلتين عشريتين.');
    }

    public function messages(): array
    {
        return [
            'opening_date.required' => 'تاريخ الافتتاح مطلوب.',
            'opening_date.date_format' => 'أدخل تاريخ الافتتاح بصيغة YYYY-MM-DD.',
            'currency.required' => 'العملة مطلوبة.',
            'currency.string' => 'العملة غير صالحة.',
            'currency.regex' => 'العملة لا تطابق عملة المحاسبة المعتمدة.',
            'currency.in' => 'العملة لا تطابق عملة المحاسبة المعتمدة.',
            'lines.required' => 'أضف سطرين على الأقل للأرصدة الافتتاحية.',
            'lines.min' => 'أضف سطرين على الأقل للأرصدة الافتتاحية.',
            'lines.array' => 'سطور الأرصدة الافتتاحية غير صالحة.',
            'lines.max' => 'عدد السطور يتجاوز الحد المسموح.',
            'lines.*.array' => 'بيانات السطر غير صالحة.',
            'lines.*.chart_account_id.required' => 'اختر الحساب لكل سطر.',
            'lines.*.chart_account_id.integer' => 'الحساب المختار غير صالح.',
            'lines.*.chart_account_id.min' => 'الحساب المختار غير صالح.',
            'lines.*.debit.required' => 'أدخل قيمة المدين.',
            'lines.*.credit.required' => 'أدخل قيمة الدائن.',
            'lines.*.debit.string' => 'أدخل المدين كنص عشري صحيح.',
            'lines.*.credit.string' => 'أدخل الدائن كنص عشري صحيح.',
            'id.missing' => 'لا يمكن تعديل بيانات الدفعة المحمية.',
            'user_id.missing' => 'لا يمكن تعديل مالك الدفعة.',
            'status.missing' => 'لا يمكن تعديل حالة الدفعة مباشرة.',
            'journal_entry_id.missing' => 'لا يمكن تعديل القيد المرتبط مباشرة.',
            'posted_at.missing' => 'لا يمكن تعديل وقت الترحيل مباشرة.',
            'created_at.missing' => 'لا يمكن تعديل بيانات التدقيق.',
            'updated_at.missing' => 'لا يمكن تعديل بيانات التدقيق.',
        ];
    }
}
