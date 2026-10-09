<?php

namespace Tests\Feature;

use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\ReviewCashAccountRole;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Models\ChartAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class CashFlowJournalLifecycleTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code, string $type, ?string $role): ChartAccount
    {
        $account = $owner->chartAccounts()->create(['code' => $code, 'name' => $code, 'type' => $type]);
        if ($role !== null) {
            (new ReviewCashAccountRole)->execute($owner, $account->id, $role);
        }

        return $account;
    }

    private function line(ChartAccount $account, string $debit, string $credit): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit];
    }

    private function allocation(int $debitIndex, int $creditIndex, string $amount, ?string $category): array
    {
        return ['debit_line_index' => $debitIndex, 'credit_line_index' => $creditIndex,
            'amount' => $amount, 'category' => $category];
    }

    private function draft(User $owner, array $lines, array $allocations = []): \App\Models\JournalEntry
    {
        return (new SaveJournalDraft)->execute($owner, '2026-10-09', config('accounting.currency'),
            $lines, allocations: $allocations);
    }

    private function rows(int $journalId): array
    {
        return DB::table('journal_line_allocations')->where('journal_entry_id', $journalId)->orderBy('id')->get()->all();
    }

    public function test_draft_can_be_incomplete_and_line_replacement_removes_stale_allocations(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $lines = [$this->line($expense, '60.00', '0.00'), $this->line($cash, '0.00', '100.00')];
        $journal = $this->draft($owner, $lines, [$this->allocation(0, 1, '60.00', 'operating')]);
        $oldLineIds = $journal->lines->pluck('id')->all();
        $this->assertCount(1, $this->rows($journal->id));
        $this->assertSame('draft', $journal->status);

        try {
            (new SaveJournalDraft)->execute($owner, '2026-10-09', config('accounting.currency'), $lines,
                journalId: $journal->id, version: $journal->version + 1,
                allocations: [$this->allocation(0, 1, '1.00', 'operating')]);
            $this->fail('Stale draft allocation edit should be rejected.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::JournalDraftStale, $exception->reason);
        }
        $this->assertSame('60.00', $this->rows($journal->id)[0]->amount);
        try {
            (new SaveJournalDraft)->execute($owner, '2026-10-09', config('accounting.currency'), $lines,
                journalId: $journal->id, version: $journal->version,
                allocations: [$this->allocation(0, 9, '1.00', 'operating')]);
            $this->fail('Missing submitted line should reject the draft edit.');
        } catch (ValidationException) {
            $this->assertSame('60.00', $this->rows($journal->id)[0]->amount);
            $this->assertSame($oldLineIds, $journal->fresh()->lines->pluck('id')->all());
        }

        $updated = (new SaveJournalDraft)->execute($owner, '2026-10-09', config('accounting.currency'),
            [$this->line($expense, '100.00', '0.00'), $this->line($cash, '0.00', '100.00')],
            journalId: $journal->id, version: $journal->version);
        $this->assertSame([], $this->rows($journal->id));
        $this->assertEmpty(array_intersect($oldLineIds, $updated->lines->pluck('id')->all()));
        $this->assertSame(2, $updated->version);

        $replaced = (new SaveJournalDraft)->execute($owner, '2026-10-09', config('accounting.currency'),
            [$this->line($expense, '100.00', '0.00'), $this->line($cash, '0.00', '100.00')],
            journalId: $journal->id, version: $updated->version,
            allocations: [$this->allocation(0, 1, '25.00', 'operating')]);
        $this->assertSame('25.00', $this->rows($journal->id)[0]->amount);
        $this->assertSame($replaced->lines[0]->id, $this->rows($journal->id)[0]->debit_line_id);
    }

    public function test_draft_allocation_input_rejects_foreign_indices_and_inexact_values(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $lines = [$this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00')];
        foreach ([
            $this->allocation(0, 9, '1.00', 'operating'),
            $this->allocation(0, 1, '1.001', 'operating'),
            $this->allocation(0, 1, '1.00', 'OPERATING'),
            ['debit_line_id' => 1, 'credit_line_id' => 2, 'amount' => '1.00', 'category' => 'operating'],
        ] as $allocation) {
            try {
                $this->draft($owner, $lines, [$allocation]);
                $this->fail('Expected invalid draft allocation.');
            } catch (ValidationException|AccountingConflict) {
                $this->assertDatabaseCount('journal_entries', 0);
            }
        }
    }

    public function test_closed_period_still_blocks_draft_allocation_edits(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $lines = [$this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00')];
        $journal = $this->draft($owner, $lines, [$this->allocation(0, 1, '1.00', 'operating')]);
        $period = (new CreateAccountingPeriod)->execute($owner, '2026-10-01', '2026-10-31');
        (new CloseAccountingPeriod)->execute($owner, $period->id);
        try {
            (new SaveJournalDraft)->execute($owner, '2026-10-09', config('accounting.currency'), $lines,
                journalId: $journal->id, version: $journal->version,
                allocations: [$this->allocation(0, 1, '0.50', 'operating')]);
            $this->fail('Closed period should block draft allocation edits.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::AccountingPeriodClosed, $exception->reason);
        }
        $this->assertSame('1.00', $this->rows($journal->id)[0]->amount);
    }

    public function test_post_requires_reviewed_roles_and_complete_cash_allocations(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', null);
        $journal = $this->draft($owner, [$this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00')]);
        try {
            (new PostJournalEntry)->execute($owner, $journal->id);
            $this->fail('Unreviewed account should block posting.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowUnreviewedAccount, $exception->reason);
        }
        (new ReviewCashAccountRole)->execute($owner, $expense->id, 'non_cash');
        try {
            (new PostJournalEntry)->execute($owner, $journal->id);
            $this->fail('Missing cash allocation should block posting.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowInvalidAllocation, $exception->reason);
        }
        $this->assertTrue($journal->fresh()->isDraft());
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_post_seals_exact_split_and_reversal_inherits_opposite_flow(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $asset = $this->account($owner, '1500', 'asset', 'non_cash');
        $liability = $this->account($owner, '2000', 'liability', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '30.01', '0.00'), $this->line($asset, '39.99', '0.00'),
            $this->line($liability, '30.00', '0.00'), $this->line($cash, '0.00', '100.00'),
        ], [
            $this->allocation(0, 3, '30.01', 'operating'),
            $this->allocation(1, 3, '39.99', 'investing'),
            $this->allocation(2, 3, '30.00', 'financing'),
        ]);
        $originalRows = $this->rows($journal->id);
        $posted = (new PostJournalEntry)->execute($owner, $journal->id);
        $this->assertTrue($posted->isPosted());
        $this->assertDatabaseHas('cash_flow_journal_completions', ['journal_entry_id' => $journal->id]);
        $this->assertSame($posted->id, (new PostJournalEntry)->execute($owner, $journal->id)->id);

        $reversal = (new ReverseJournalEntry)->execute($owner, $journal->id);
        $this->assertTrue($reversal->isPosted());
        $this->assertDatabaseHas('cash_flow_journal_completions', ['journal_entry_id' => $reversal->id]);
        $reverseRows = $this->rows($reversal->id);
        $this->assertCount(3, $reverseRows);
        $reverseIds = $reversal->lines->pluck('id', 'line_number');
        foreach ($originalRows as $index => $row) {
            $this->assertSame((int) $reverseIds->get($index + 1), (int) $reverseRows[$index]->credit_line_id);
            $this->assertSame((int) $reverseIds->get(4), (int) $reverseRows[$index]->debit_line_id);
            $this->assertSame($row->amount, $reverseRows[$index]->amount);
            $this->assertSame($row->category, $reverseRows[$index]->category);
        }
    }

    public function test_non_cash_post_and_reversal_need_no_completion(): void
    {
        $owner = User::factory()->create();
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $payable = $this->account($owner, '2000', 'liability', 'non_cash');
        $journal = $this->draft($owner, [$this->line($expense, '1.00', '0.00'), $this->line($payable, '0.00', '1.00')]);
        $this->assertTrue((new PostJournalEntry)->execute($owner, $journal->id)->isPosted());
        $this->assertTrue((new ReverseJournalEntry)->execute($owner, $journal->id)->isPosted());
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_draft_delete_removes_allocation_rows_before_lines(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00'),
        ], [$this->allocation(0, 1, '1.00', 'operating')]);
        (new DeleteJournalDraft)->execute($owner, $journal->id);
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_opening_balance_reversal_does_not_require_cash_roles_or_allocations(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', null);
        $equity = $this->account($owner, '3000', 'equity', null);
        $journalId = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
        foreach ([[$cash, '1.00', '0.00'], [$equity, '0.00', '1.00']] as $index => [$account, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'user_id' => $owner->id, 'journal_entry_id' => $journalId,
                'chart_account_id' => $account->id, 'line_number' => $index + 1,
                'debit' => $debit, 'credit' => $credit,
            ]);
        }
        DB::table('journal_entries')->where('id', $journalId)->update(['status' => 'posted', 'posted_at' => now()]);
        $batchId = DB::table('opening_balance_batches')->insertGetId([
            'user_id' => $owner->id, 'opening_date' => '2026-10-09', 'currency' => config('accounting.currency'),
        ]);
        DB::table('opening_balance_batches')->where('id', $batchId)->update([
            'status' => 'posted', 'journal_entry_id' => $journalId, 'posted_at' => now(),
        ]);

        $reversal = (new ReverseJournalEntry)->execute($owner, $journalId);
        $this->assertTrue($reversal->isPosted());
        $this->assertDatabaseCount('journal_line_allocations', 0);
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_ordinary_historical_cash_journal_without_completion_cannot_be_reversed(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00'),
        ]);
        DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'posted', 'posted_at' => now()]);
        try {
            (new ReverseJournalEntry)->execute($owner, $journal->id);
            $this->fail('Incomplete historical cash journal should not be reversed.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowInvalidAllocation, $exception->reason);
        }
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_http_draft_accepts_zero_based_allocation_indices(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $response = $this->actingAs($owner)->postJson('/accounting/journals', [
            'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
            'lines' => [$this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00')],
            'allocations' => [$this->allocation(0, 1, '1.00', 'operating')],
        ])->assertCreated();
        $this->assertCount(1, $this->rows($response->json('data.id')));
        $response->assertJsonPath('data.allocations.0.debit_line_index', 0)
            ->assertJsonPath('data.allocations.0.credit_line_index', 1)
            ->assertJsonPath('data.allocations.0.amount', '1.00');
        $this->getJson('/accounting/journals/'.$response->json('data.id'))
            ->assertOk()->assertJsonPath('data.allocations.0.category', 'operating');
    }

    public function test_page_accepts_string_line_indices_and_restores_saved_allocations(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $this->actingAs($owner)->get(route('accounting-pages.journals.create'))
            ->assertOk()->assertSee('data-add-allocation', false);
        $response = $this->post(route('accounting-pages.journals.store'), [
            'entry_date' => '2026-10-09', 'currency' => config('accounting.currency'),
            'lines' => [$this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00')],
            'allocations' => [['debit_line_index' => '0', 'credit_line_index' => '1',
                'amount' => '1.00', 'category' => 'operating']],
        ]);
        $journalId = (int) DB::table('journal_entries')->where('user_id', $owner->id)->value('id');
        $response->assertRedirect(route('accounting-pages.journals.show', $journalId));
        $this->get(route('accounting-pages.journals.edit', $journalId))
            ->assertOk()->assertSee('name="allocations[0][amount]"', false)
            ->assertSee('name="allocations[0][debit_line_index]"', false);
        $this->assertCount(1, $this->rows($journalId));
    }

    public function test_completion_failure_rolls_back_posting_and_keeps_draft_allocations(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '1.00', '0.00'), $this->line($cash, '0.00', '1.00'),
        ], [$this->allocation(0, 1, '1.00', 'operating')]);
        $before = array_map(fn ($row) => (array) $row, $this->rows($journal->id));
        $failedAfterInsert = false;
        DB::listen(function ($event) use (&$failedAfterInsert): void {
            if (! $failedAfterInsert && $event->connectionName === 'mysql_testing'
                && str_starts_with(strtolower(ltrim($event->sql)), 'insert into')
                && str_contains($event->sql, 'cash_flow_journal_completions')) {
                $failedAfterInsert = true;
                throw new RuntimeException('Injected completion failure');
            }
        });
        try {
            (new PostJournalEntry)->execute($owner, $journal->id);
            $this->fail('Expected injected completion failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected completion failure', $exception->getMessage());
        }
        $this->assertTrue($failedAfterInsert);
        $this->assertTrue($journal->fresh()->isDraft());
        $this->assertSame($before, array_map(fn ($row) => (array) $row, $this->rows($journal->id)));
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }

    public function test_copied_allocation_failure_rolls_back_reversal(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $asset = $this->account($owner, '1500', 'asset', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '60.00', '0.00'), $this->line($asset, '40.00', '0.00'),
            $this->line($cash, '0.00', '100.00'),
        ], [$this->allocation(0, 2, '60.00', 'operating'), $this->allocation(1, 2, '40.00', 'investing')]);
        (new PostJournalEntry)->execute($owner, $journal->id);
        $failedAfterInsert = false;
        DB::listen(function ($event) use (&$failedAfterInsert): void {
            if (! $failedAfterInsert && $event->connectionName === 'mysql_testing'
                && str_starts_with(strtolower(ltrim($event->sql)), 'insert into')
                && str_contains($event->sql, 'journal_line_allocations')) {
                $failedAfterInsert = true;
                throw new RuntimeException('Injected copied-allocation failure');
            }
        });
        try {
            (new ReverseJournalEntry)->execute($owner, $journal->id);
            $this->fail('Expected copied-allocation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected copied-allocation failure', $exception->getMessage());
        }
        $this->assertTrue($failedAfterInsert);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_line_allocations', 2);
        $this->assertDatabaseCount('cash_flow_journal_completions', 1);
    }

    public function test_post_rejects_incomplete_and_corrupt_draft_allocations_without_transition(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $asset = $this->account($owner, '1500', 'asset', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '60.00', '0.00'), $this->line($asset, '40.00', '0.00'),
            $this->line($cash, '0.00', '100.00'),
        ], [$this->allocation(0, 2, '60.00', 'operating')]);
        $ids = $journal->lines->pluck('id')->all();
        $post = new PostJournalEntry;
        foreach ([
            ['debit_line_id' => $ids[1], 'credit_line_id' => $ids[2], 'amount' => '40.01', 'category' => 'investing'],
            ['debit_line_id' => $ids[0], 'credit_line_id' => $ids[2], 'amount' => '60.00', 'category' => null],
        ] as $badRow) {
            DB::table('journal_line_allocations')->where('journal_entry_id', $journal->id)->delete();
            DB::table('journal_line_allocations')->insert($badRow + ['user_id' => $owner->id, 'journal_entry_id' => $journal->id]);
            try {
                $post->execute($owner, $journal->id);
                $this->fail('Invalid draft allocations should block posting.');
            } catch (AccountingConflict $exception) {
                $this->assertSame(AccountingConflictReason::CashFlowInvalidAllocation, $exception->reason);
            }
            $this->assertTrue($journal->fresh()->isDraft());
            $this->assertDatabaseCount('cash_flow_journal_completions', 0);
        }
    }

    public function test_cash_to_cash_transfer_posts_and_reverses_without_activity_category(): void
    {
        $owner = User::factory()->create();
        $cash = $this->account($owner, '1000', 'asset', 'cash');
        $equivalent = $this->account($owner, '1100', 'asset', 'cash_equivalent');
        $journal = $this->draft($owner, [
            $this->line($equivalent, '10.01', '0.00'), $this->line($cash, '0.00', '10.01'),
        ], [$this->allocation(0, 1, '10.01', null)]);
        $this->assertTrue((new PostJournalEntry)->execute($owner, $journal->id)->isPosted());
        $reversal = (new ReverseJournalEntry)->execute($owner, $journal->id);
        $this->assertNull($this->rows($journal->id)[0]->category);
        $this->assertNull($this->rows($reversal->id)[0]->category);
        $this->assertDatabaseCount('cash_flow_journal_completions', 2);
    }

    public function test_post_rejects_cash_transfer_category_and_counterpart_over_capacity(): void
    {
        $owner = User::factory()->create();
        $cashA = $this->account($owner, '1000', 'asset', 'cash');
        $cashB = $this->account($owner, '1100', 'asset', 'cash_equivalent');
        $transfer = $this->draft($owner, [
            $this->line($cashA, '1.00', '0.00'), $this->line($cashB, '0.00', '1.00'),
        ]);
        DB::table('journal_line_allocations')->insert([
            'user_id' => $owner->id, 'journal_entry_id' => $transfer->id,
            'debit_line_id' => $transfer->lines[0]->id, 'credit_line_id' => $transfer->lines[1]->id,
            'amount' => '1.00', 'category' => 'financing',
        ]);
        try {
            (new PostJournalEntry)->execute($owner, $transfer->id);
            $this->fail('Cash transfer category should be rejected.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowInvalidAllocation, $exception->reason);
        }
        $this->assertTrue($transfer->fresh()->isDraft());

        $expense = $this->account($owner, '5000', 'expense', 'non_cash');
        $asset = $this->account($owner, '1500', 'asset', 'non_cash');
        $journal = $this->draft($owner, [
            $this->line($expense, '60.00', '0.00'), $this->line($asset, '40.00', '0.00'),
            $this->line($cashA, '0.00', '50.00'), $this->line($cashB, '0.00', '50.00'),
        ]);
        foreach ([
            [$journal->lines[0]->id, $journal->lines[2]->id, '50.00', 'operating'],
            [$journal->lines[0]->id, $journal->lines[3]->id, '11.00', 'operating'],
            [$journal->lines[1]->id, $journal->lines[3]->id, '39.00', 'investing'],
        ] as [$debit, $credit, $amount, $category]) {
            DB::table('journal_line_allocations')->insert([
                'user_id' => $owner->id, 'journal_entry_id' => $journal->id,
                'debit_line_id' => $debit, 'credit_line_id' => $credit,
                'amount' => $amount, 'category' => $category,
            ]);
        }
        try {
            (new PostJournalEntry)->execute($owner, $journal->id);
            $this->fail('Counterpart over-capacity should be rejected.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::CashFlowInvalidAllocation, $exception->reason);
        }
        $this->assertTrue($journal->fresh()->isDraft());
        $this->assertDatabaseCount('cash_flow_journal_completions', 0);
    }
}
