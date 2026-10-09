<?php

namespace App\Http\Presenters;

use App\Accounting\Exceptions\OpeningBalanceAccountsUnavailable;
use Illuminate\Validation\ValidationException;

final class OpeningBalanceValidationPresentation
{
    public function message(ValidationException $exception): string
    {
        $errors = $exception->errors();
        if (isset($errors['currency'])) {
            return 'العملة لا تطابق عملة المحاسبة المعتمدة.';
        }
        foreach (array_keys($errors) as $field) {
            if (preg_match('/^lines\.\d+\.chart_account_id$/D', $field)) {
                return 'الحساب مكرر داخل الدفعة.';
            }
        }
        if ($exception instanceof OpeningBalanceAccountsUnavailable) {
            return 'لا يمكن استخدام حساب غير نشط أو غير متاح.';
        }

        return 'الأرصدة الافتتاحية غير متوازنة أو غير صالحة.';
    }
}
