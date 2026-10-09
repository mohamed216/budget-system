<?php

namespace Tests\Unit;

use App\Accounting\Exceptions\OpeningBalanceAccountsUnavailable;
use App\Http\Presenters\OpeningBalanceValidationPresentation;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OpeningBalanceValidationPresentationTest extends TestCase
{
    public function test_account_classification_uses_exception_type_and_keeps_other_validation_distinct(): void
    {
        $presentation = new OpeningBalanceValidationPresentation;
        $message = 'Opening balance accounts must exist, belong to the actor, and be active.';

        $this->assertSame('لا يمكن استخدام حساب غير نشط أو غير متاح.',
            $presentation->message(OpeningBalanceAccountsUnavailable::withMessages(['lines' => $message])));
        $this->assertSame('الأرصدة الافتتاحية غير متوازنة أو غير صالحة.',
            $presentation->message(ValidationException::withMessages(['lines' => $message])));
        $this->assertSame('العملة لا تطابق عملة المحاسبة المعتمدة.',
            $presentation->message(ValidationException::withMessages(['currency' => 'Different wording.'])));
        $this->assertSame('الحساب مكرر داخل الدفعة.',
            $presentation->message(ValidationException::withMessages(['lines.1.chart_account_id' => 'Different wording.'])));
    }
}
