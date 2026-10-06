<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\User;
use App\Policies\FinancialRecordPolicy;
use PHPUnit\Framework\TestCase;

class FinancialRecordPolicyTest extends TestCase
{
    public function test_financial_policy_rejects_foreign_and_unassigned_records(): void
    {
        $user = new User;
        $user->id = 1;
        $record = new Account;
        $policy = new FinancialRecordPolicy;

        $this->assertFalse($policy->view($user, $record));
        $record->user_id = 2;
        $this->assertFalse($policy->view($user, $record));
        $this->assertFalse($policy->delete($user, $record));
        $record->user_id = 1;
        $this->assertTrue($policy->view($user, $record));
        $this->assertTrue($policy->delete($user, $record));
    }
}
