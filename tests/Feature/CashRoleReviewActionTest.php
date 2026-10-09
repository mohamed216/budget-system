<?php

namespace Tests\Feature;

use App\Accounting\Actions\ReviewCashAccountRole;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class CashRoleReviewActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code, string $type = 'asset', bool $active = true): ChartAccount
    {
        return $owner->chartAccounts()->create([
            'code' => $code, 'name' => $code, 'type' => $type, 'is_active' => $active,
        ]);
    }

    private function postWithAccount(User $owner, ChartAccount $account): void
    {
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
        foreach ([['1.00', '0.00'], ['0.00', '1.00']] as $index => [$debit, $credit]) {
            DB::table('journal_lines')->insert([
                'user_id' => $owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $account->id,
                'line_number' => $index + 1, 'debit' => $debit, 'credit' => $credit,
            ]);
        }
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()]);
    }

    public function test_owner_reviews_unresolved_cash_roles_and_inactive_history(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000');
        $equivalent = $this->account($owner, '1100', active: false);
        $action = new ReviewCashAccountRole;

        $this->assertSame('cash', $action->execute($owner, $cash->id, 'cash')->cash_role);
        $this->assertSame('cash_equivalent', $action->execute($owner, $equivalent->id, 'cash_equivalent')->cash_role);
        $this->assertFalse($equivalent->fresh()->is_active);
        $this->assertSame('cash', $action->execute($owner, $cash->id, 'cash')->cash_role);
    }

    public function test_non_asset_accounts_can_only_be_reviewed_as_non_cash(): void
    {
        $owner = User::factory()->create();
        $action = new ReviewCashAccountRole;
        foreach (['liability', 'equity', 'revenue', 'expense'] as $type) {
            $account = $this->account($owner, $type, $type);
            foreach (['cash', 'cash_equivalent'] as $cashRole) {
                try {
                    $action->execute($owner, $account->id, $cashRole);
                    $this->fail('Expected a non-asset cash-role rejection.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('cash_role', $exception->errors());
                }
                $this->assertNull($account->fresh()->cash_role);
            }
            $this->assertSame('non_cash', $action->execute($owner, $account->id, 'non_cash')->cash_role);
        }
    }

    public function test_foreign_account_is_not_visible_and_invalid_roles_are_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->account($other, '1000');
        $action = new ReviewCashAccountRole;
        $this->expectException(ModelNotFoundException::class);
        $action->execute($owner, $foreign->id, 'cash');
    }

    public function test_invalid_and_case_distinct_role_inputs_never_mutate(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, '1000');
        $action = new ReviewCashAccountRole;
        foreach (['CASH', 'Cash', 'cash ', 'other', ''] as $role) {
            try {
                $action->execute($owner, $account->id, $role);
                $this->fail('Expected invalid role input.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cash_role', $exception->errors());
            }
            $this->assertNull($account->fresh()->cash_role);
        }
    }

    public function test_known_role_changes_only_before_posted_use_and_bootstrap_is_one_time(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, '1000');
        $action = new ReviewCashAccountRole;
        $action->execute($owner, $account->id, 'non_cash');
        $this->assertSame('cash', $action->execute($owner, $account->id, 'cash')->cash_role);
        $this->postWithAccount($owner, $account);
        $this->assertSame('cash', $action->execute($owner, $account->id, 'cash')->cash_role);
        try {
            $action->execute($owner, $account->id, 'cash_equivalent');
            $this->fail('Expected a frozen role conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashRoleFrozen, $exception->reason);
        }
        $this->assertSame('cash', $account->fresh()->cash_role);

        $historical = $this->account($owner, '1100');
        $this->postWithAccount($owner, $historical);
        $this->assertNull($historical->fresh()->cash_role);
        $this->assertSame('cash_equivalent', $action->execute($owner, $historical->id, 'cash_equivalent')->cash_role);
        try {
            $action->execute($owner, $historical->id, 'non_cash');
            $this->fail('Expected bootstrap role to freeze after review.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashRoleFrozen, $exception->reason);
        }
    }
}
