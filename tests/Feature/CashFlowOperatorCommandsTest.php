<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class CashFlowOperatorCommandsTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code, string $type = 'asset', ?string $role = null, bool $active = true): int
    {
        $account = $owner->chartAccounts()->create([
            'code' => $code, 'name' => $code, 'type' => $type, 'cash_role' => $role, 'is_active' => $active,
        ]);

        return $account->id;
    }

    private function journal(User $owner, array $lines, string $date = '2026-10-01', ?int $reversalOf = null): array
    {
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => $date, 'currency' => config('accounting.currency'),
            'reversal_of_id' => $reversalOf,
        ]);
        $ids = [];
        foreach ($lines as $index => [$account, $debit, $credit]) {
            $ids[] = DB::table('journal_lines')->insertGetId([
                'user_id' => $owner->id, 'journal_entry_id' => $journal, 'chart_account_id' => $account,
                'line_number' => $index + 1, 'debit' => $debit, 'credit' => $credit,
            ]);
        }
        DB::table('journal_entries')->where('id', $journal)->update(['status' => 'posted', 'posted_at' => now()]);

        return [$journal, $ids];
    }

    private function allocationFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cash-flow-');
        file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => unlink($path));

        return $path;
    }

    public function test_role_command_reviews_inactive_account_and_same_role_retry_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner, '1000', active: false);
        $args = ['user' => (string) $owner->id, 'account' => (string) $account, 'role' => 'cash_equivalent'];
        $this->assertSame(0, Artisan::call('accounting:cash-role-review', $args));
        $this->assertSame('cash_equivalent', DB::table('chart_of_accounts')->where('id', $account)->value('cash_role'));
        $this->assertSame(0, Artisan::call('accounting:cash-role-review', $args));
    }

    public function test_role_command_rejects_invalid_nonasset_foreign_and_frozen_changes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $liability = $this->account($owner, '2000', 'liability');
        $foreign = $this->account($other, '1000');
        foreach ([[$liability, 'cash'], [$liability, 'CASH'], [$foreign, 'cash']] as [$account, $role]) {
            $this->assertNotSame(0, Artisan::call('accounting:cash-role-review', [
                'user' => (string) $owner->id, 'account' => (string) $account, 'role' => $role,
            ]));
        }
        $cash = $this->account($owner, '1100');
        $this->journal($owner, [[$cash, '1.00', '0.00'], [$liability, '0.00', '1.00']]);
        $this->assertSame(0, Artisan::call('accounting:cash-role-review', [
            'user' => (string) $owner->id, 'account' => (string) $cash, 'role' => 'cash',
        ]));
        $this->assertNotSame(0, Artisan::call('accounting:cash-role-review', [
            'user' => (string) $owner->id, 'account' => (string) $cash, 'role' => 'non_cash',
        ]));
        $this->assertStringContainsString('CashRoleFrozen', Artisan::output());
        $this->assertSame('cash', DB::table('chart_of_accounts')->where('id', $cash)->value('cash_role'));
    }

    public function test_completion_command_seals_explicit_allocation_and_rejects_retry(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', role: 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        [$journal, $lines] = $this->journal($owner, [[$expense, '1.23', '0.00'], [$cash, '0.00', '1.23']]);
        $file = $this->allocationFile([[
            'debit_line_id' => $lines[0], 'credit_line_id' => $lines[1], 'amount' => '1.23', 'category' => 'operating',
        ]]);
        $args = ['user' => (string) $owner->id, 'journal' => (string) $journal, 'file' => $file];
        $this->assertSame(0, Artisan::call('accounting:cash-flow-complete', $args));
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
        $this->assertNotSame(0, Artisan::call('accounting:cash-flow-complete', $args));
        $this->assertStringContainsString('CashFlowAlreadyCompleted', Artisan::output());
        $this->assertDatabaseCount('journal_line_allocations', 1);
    }

    public function test_completion_command_rejects_invalid_foreign_and_reversal_without_partial_writes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $cash = $this->account($owner, '1000', role: 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        [$journal, $lines] = $this->journal($owner, [[$expense, '1.00', '0.00'], [$cash, '0.00', '1.00']]);
        [$reversal] = $this->journal($owner, [[$cash, '1.00', '0.00'], [$expense, '0.00', '1.00']], reversalOf: $journal);
        [$foreign] = $this->journal($other, [
            [$this->account($other, '1000', role: 'cash'), '1.00', '0.00'],
            [$this->account($other, '2000', 'liability', 'non_cash'), '0.00', '1.00'],
        ]);
        $file = $this->allocationFile([[
            'debit_line_id' => $lines[0], 'credit_line_id' => $lines[1], 'amount' => '0.99', 'category' => 'operating',
        ]]);
        foreach ([$journal, $reversal, $foreign] as $id) {
            $this->assertNotSame(0, Artisan::call('accounting:cash-flow-complete', [
                'user' => (string) $owner->id, 'journal' => (string) $id, 'file' => $file,
            ]));
            if ($id === $reversal) {
                $this->assertStringContainsString('CashFlowReversalRequiresInheritance', Artisan::output());
            }
        }
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_completion_command_rejects_opening_balance_and_fiscal_close_journals(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', role: 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        [$openingJournal, $openingLines] = $this->journal($owner, [[$expense, '1.00', '0.00'], [$cash, '0.00', '1.00']]);
        $batch = DB::table('opening_balance_batches')->insertGetId([
            'user_id' => $owner->id, 'opening_date' => '2026-10-01', 'currency' => config('accounting.currency'),
        ]);
        DB::table('opening_balance_batches')->where('id', $batch)->update([
            'status' => 'posted', 'journal_entry_id' => $openingJournal, 'posted_at' => now(),
        ]);
        $retained = $this->account($owner, '3000', 'equity', 'non_cash');
        [$closingJournal] = $this->journal($owner, [[$expense, '1.00', '0.00'], [$retained, '0.00', '1.00']]);
        DB::table('fiscal_year_closes')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $retained,
            'journal_entry_id' => $closingJournal, 'closed_at' => now(),
        ]);
        $file = $this->allocationFile([[
            'debit_line_id' => $openingLines[0], 'credit_line_id' => $openingLines[1],
            'amount' => '1.00', 'category' => 'operating',
        ]]);
        foreach ([$openingJournal, $closingJournal] as $journal) {
            $this->assertNotSame(0, Artisan::call('accounting:cash-flow-complete', [
                'user' => (string) $owner->id, 'journal' => (string) $journal, 'file' => $file,
            ]));
            $this->assertStringContainsString('CashFlowSpecialJournal', Artisan::output());
        }
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_readiness_lists_owned_unresolved_and_incomplete_journals_through_cutoff_without_mutation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $unresolved = $this->account($owner, '9000');
        $cash = $this->account($owner, '1000', role: 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        [$due] = $this->journal($owner, [[$expense, '1.00', '0.00'], [$cash, '0.00', '1.00']]);
        [$reversal] = $this->journal($owner, [[$cash, '1.00', '0.00'], [$expense, '0.00', '1.00']], reversalOf: $due);
        [$future] = $this->journal($owner, [[$expense, '2.00', '0.00'], [$cash, '0.00', '2.00']], '2026-11-01');
        [$nonCash] = $this->journal($owner, [[$expense, '3.00', '0.00'], [$this->account($owner, '2000', 'liability', 'non_cash'), '0.00', '3.00']]);
        $foreignAccount = $this->account($other, '7777');
        [$foreign] = $this->journal($other, [[$foreignAccount, '1.00', '0.00'], [$foreignAccount, '0.00', '1.00']]);
        $this->assertSame(0, Artisan::call('accounting:cash-flow-readiness', [
            'user' => (string) $owner->id, 'through' => '2026-10-09',
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('"account_id":'.$unresolved, $output);
        $this->assertStringContainsString('"journal_id":'.$due, $output);
        $this->assertStringContainsString('"journal_id":'.$reversal, $output);
        $this->assertStringContainsString('"manual_completion_supported":false', $output);
        $this->assertStringNotContainsString('"journal_id":'.$future, $output);
        $this->assertStringNotContainsString('"journal_id":'.$nonCash, $output);
        $this->assertStringNotContainsString('"journal_id":'.$foreign, $output);
        $this->assertStringNotContainsString('"account_id":'.$foreignAccount, $output);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
        $this->assertDatabaseCount('journal_line_allocations', 0);
    }
}
