<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class JournalPeriodGuardTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner): ChartAccount
    {
        return (new CreateChartAccount)->execute($owner, '1000', 'Cash', 'asset');
    }

    private function lines(ChartAccount $account): array
    {
        return [
            ['chart_account_id' => $account->id, 'debit' => '0.01', 'credit' => '0', 'description' => 'Debit'],
            ['chart_account_id' => $account->id, 'debit' => '0', 'credit' => '0.01', 'description' => 'Credit'],
        ];
    }

    private function draft(User $owner, ChartAccount $account, string $date = '2026-01-15'): JournalEntry
    {
        return (new SaveJournalDraft)->execute($owner, $date, config('accounting.currency'), $this->lines($account));
    }

    private function closed(User $owner, string $start, string $end): void
    {
        $period = (new CreateAccountingPeriod)->execute($owner, $start, $end);
        (new CloseAccountingPeriod)->execute($owner, $period->id);
    }

    private function conflict(callable $operation): void
    {
        $this->expectFailure($operation, AccountingConflict::class);
    }

    private function expectFailure(callable $operation, string $exceptionClass): void
    {
        try {
            $operation();
            $this->fail("Expected {$exceptionClass}.");
        } catch (\Throwable $exception) {
            if ($exception instanceof \PHPUnit\Framework\AssertionFailedError) {
                throw $exception;
            }
            $this->assertInstanceOf($exceptionClass, $exception);
        }
    }

    public function test_guard_allows_gaps_and_open_periods_but_rejects_closed_inclusive_dates(): void
    {
        $owner = User::factory()->create();
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            (new PeriodGuard)->assertOpen($owner, '2026-02-01');
        });
        (new CreateAccountingPeriod)->execute($owner, '2026-02-01', '2026-02-28');
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            (new PeriodGuard)->assertOpen($owner, '2026-02-01');
            (new PeriodGuard)->assertOpen($owner, '2026-02-28');
        });
        $period = $owner->accountingPeriods()->firstOrFail();
        (new CloseAccountingPeriod)->execute($owner, $period->id);
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-02-01'));
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-02-28'));
            (new PeriodGuard)->assertOpen($owner, '2026-03-01');
        });
    }

    public function test_guard_rejects_any_overlapping_closed_period_regardless_of_row_order(): void
    {
        $owner = User::factory()->create();
        // Bypass period actions to simulate overlapping rows introduced by direct SQL/import.
        DB::table('accounting_periods')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-04-01', 'end_date' => '2026-04-30', 'status' => 'open',
        ]);
        DB::table('accounting_periods')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-04-10', 'end_date' => '2026-04-20', 'status' => 'closed', 'first_closed_at' => now(),
        ]);
        // Here the closed row has the earlier id, while the open row sorts first by start_date.
        DB::table('accounting_periods')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-05-10', 'end_date' => '2026-05-20', 'status' => 'closed', 'first_closed_at' => now(),
        ]);
        DB::table('accounting_periods')->insert([
            'user_id' => $owner->id, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'status' => 'open',
        ]);

        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-04-15'));
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-05-15'));
        });
    }

    public function test_guard_allows_multiple_corrupt_overlapping_open_periods(): void
    {
        $owner = User::factory()->create();
        DB::table('accounting_periods')->insert([
            ['user_id' => $owner->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open'],
            ['user_id' => $owner->id, 'start_date' => '2026-06-10', 'end_date' => '2026-06-20', 'status' => 'open'],
        ]);
        $coveringStatuses = DB::table('accounting_periods')->where('user_id', $owner->id)
            ->where('start_date', '<=', '2026-06-15')->where('end_date', '>=', '2026-06-15')
            ->orderBy('id')->pluck('status')->all();
        $this->assertSame(['open', 'open'], $coveringStatuses);

        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            (new PeriodGuard)->assertOpen($owner, '2026-06-15');
        });
    }

    public function test_draft_create_and_same_date_update_in_closed_period_are_rejected(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $this->closed($owner, '2026-01-01', '2026-01-31');
        $this->conflict(fn () => $this->draft($owner, $account));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);

        $period = $owner->accountingPeriods()->firstOrFail();
        (new \App\Accounting\Actions\ReopenAccountingPeriod)->execute($owner, $period->id);
        $draft = $this->draft($owner, $account);
        (new CloseAccountingPeriod)->execute($owner, $period->id);
        $this->conflict(fn () => (new SaveJournalDraft)->execute($owner, '2026-01-15', config('accounting.currency'), $this->lines($account), journalId: $draft->id, version: $draft->version));
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_draft_can_move_out_of_closed_period_and_open_dates_still_work(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $draft = $this->draft($owner, $account);
        $this->closed($owner, '2026-01-01', '2026-01-31');
        $open = (new CreateAccountingPeriod)->execute($owner, '2026-02-01', '2026-02-28');
        $moved = (new SaveJournalDraft)->execute($owner, '2026-02-01', config('accounting.currency'), $this->lines($account), journalId: $draft->id, version: $draft->version);
        $this->assertSame('2026-02-01', $moved->entry_date->toDateString());
        $this->assertSame(2, $moved->version);
        $this->assertTrue($open->isOpen());
        $this->assertTrue((new PostJournalEntry)->execute($owner, $draft->id)->isPosted());
        $this->assertTrue($this->draft($owner, $account, '2026-03-01')->isDraft());
    }

    public function test_post_in_closed_period_rejects_unchanged_but_posted_retry_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $draft = $this->draft($owner, $account);
        $this->closed($owner, '2026-01-01', '2026-01-31');
        $this->conflict(fn () => (new PostJournalEntry)->execute($owner, $draft->id));
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertNull($draft->fresh()->posted_at);
        $this->assertSame(1, $draft->fresh()->version);
        $this->assertDatabaseCount('journal_lines', 2);

        $other = $this->draft($owner, $account, '2026-02-01');
        $posted = (new PostJournalEntry)->execute($owner, $other->id);
        $this->closed($owner, '2026-02-01', '2026-02-28');
        $retry = (new PostJournalEntry)->execute($owner, $other->id);
        $this->assertSame($posted->id, $retry->id);
        $this->assertSame(2, $retry->version);
        $this->assertSame($posted->posted_at->format('Y-m-d H:i:s.u'), $retry->posted_at->format('Y-m-d H:i:s.u'));
    }

    public function test_reversal_of_historical_closed_entry_uses_current_open_date_and_exact_money(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07 12:00:00'));
        try {
            $owner = User::factory()->create();
            $account = $this->account($owner);
            $original = (new PostJournalEntry)->execute($owner, $this->draft($owner, $account)->id);
            $this->closed($owner, '2026-01-01', '2026-01-31');
            $reversal = (new ReverseJournalEntry)->execute($owner, $original->id);
            $this->assertSame('2026-10-07', $reversal->entry_date->toDateString());
            $this->assertTrue($reversal->isPosted());
            $this->assertSame('0.01', $reversal->lines[0]->credit);
            $this->assertSame('0.01', $reversal->lines[1]->debit);
            $this->assertTrue($original->fresh()->isPosted());
        } finally {
            $this->travelBack();
        }
    }

    public function test_reversal_in_closed_current_period_rolls_back_without_partial_rows(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07 12:00:00'));
        try {
            $owner = User::factory()->create();
            $account = $this->account($owner);
            $original = (new PostJournalEntry)->execute($owner, $this->draft($owner, $account)->id);
            $this->closed($owner, '2026-10-07', '2026-10-07');
            $this->conflict(fn () => (new ReverseJournalEntry)->execute($owner, $original->id));
            $this->assertDatabaseCount('journal_entries', 1);
            $this->assertDatabaseCount('journal_lines', 2);
            $this->assertNull($original->fresh()->reversal()->first());
        } finally {
            $this->travelBack();
        }
    }

    public function test_closed_period_does_not_change_foreign_journal_not_found_behavior(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-07 12:00:00'));
        try {
            $owner = User::factory()->create();
            $other = User::factory()->create();
            $account = $this->account($other);
            $foreign = (new PostJournalEntry)->execute($other, $this->draft($other, $account)->id);
            $this->closed($owner, '2026-01-01', '2026-12-31');
            $this->expectFailure(fn () => (new SaveJournalDraft)->execute($owner, '2026-01-15', config('accounting.currency'), [], journalId: $foreign->id, version: 1), ModelNotFoundException::class);
            $this->expectFailure(fn () => (new PostJournalEntry)->execute($owner, $foreign->id), ModelNotFoundException::class);
            $this->expectFailure(fn () => (new ReverseJournalEntry)->execute($owner, $foreign->id), ModelNotFoundException::class);
        } finally {
            $this->travelBack();
        }
    }

    public function test_reversal_lock_order_is_owner_period_header_then_lines(): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $original = (new PostJournalEntry)->execute($owner, $this->draft($owner, $account)->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new ReverseJournalEntry)->execute($owner, $original->id);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains(strtolower($q['query']), 'for update')));
            $this->assertStringContainsString('users', $locks[0]['query']);
            $this->assertStringContainsString('accounting_periods', $locks[1]['query']);
            $this->assertStringContainsString('journal_entries', $locks[2]['query']);
            $this->assertStringContainsString('journal_lines', $locks[count($locks) - 1]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /**
     * Publish only disposable draft fixtures for the second connection. Posted rows stay
     * inside a rollback-only transaction so the immutability triggers need no bypass.
     */
    private function raceFixture(callable $scenario): void
    {
        $owner = User::factory()->create();
        $account = $this->account($owner);
        $period = (new CreateAccountingPeriod)->execute($owner, '2026-01-01', '2026-01-31');
        $ownerId = $owner->id;
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.accounting_period_race' => config('database.connections.mysql_testing')]);
        $probe = DB::connection('accounting_period_race');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::commit();
        try {
            $scenario($owner, $account, $period, $probe);
        } finally {
            DB::setDefaultConnection($originalConnection);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('journal_lines')->where('user_id', $ownerId)->delete();
            DB::table('journal_entries')->where('user_id', $ownerId)->delete();
            DB::table('accounting_periods')->where('user_id', $ownerId)->delete();
            DB::table('chart_of_accounts')->where('user_id', $ownerId)->delete();
            DB::table('users')->where('id', $ownerId)->delete();
            DB::beginTransaction();
            DB::disconnect('accounting_period_race');
        }
    }

    private function assertCloseWaits(User $owner, int $periodId): void
    {
        DB::setDefaultConnection('accounting_period_race');
        try {
            (new CloseAccountingPeriod)->execute($owner, $periodId);
            $this->fail('Close must wait for the owner lock held by the journal action.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[1], [1205, 3572]);
        } finally {
            DB::setDefaultConnection('mysql_testing');
        }
    }

    public function test_close_racing_with_draft_save_waits_then_closed_date_is_rejected(): void
    {
        $this->raceFixture(function (User $owner, ChartAccount $account, $period): void {
            DB::beginTransaction();
            $this->draft($owner, $account);
            $this->assertCloseWaits($owner, $period->id);
            DB::rollBack();
            (new CloseAccountingPeriod)->execute($owner, $period->id);
            $this->conflict(fn () => $this->draft($owner, $account));
            $this->assertDatabaseCount('journal_entries', 0);
        });
    }

    public function test_close_racing_with_post_waits_then_post_sees_closed_period(): void
    {
        $this->raceFixture(function (User $owner, ChartAccount $account, $period): void {
            $draft = $this->draft($owner, $account);
            DB::beginTransaction();
            (new PostJournalEntry)->execute($owner, $draft->id);
            $this->assertCloseWaits($owner, $period->id);
            DB::rollBack();
            (new CloseAccountingPeriod)->execute($owner, $period->id);
            $this->conflict(fn () => (new PostJournalEntry)->execute($owner, $draft->id));
            $this->assertTrue($draft->fresh()->isDraft());
        });
    }

    public function test_close_racing_with_reversal_waits_then_new_reversal_is_blocked(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-01-15 12:00:00'));
        try {
            $this->raceFixture(function (User $owner, ChartAccount $account, $period): void {
                DB::beginTransaction();
                $original = (new PostJournalEntry)->execute($owner, $this->draft($owner, $account, '2025-12-15')->id);
                (new ReverseJournalEntry)->execute($owner, $original->id);
                $this->assertCloseWaits($owner, $period->id);
                DB::rollBack();
                (new CloseAccountingPeriod)->execute($owner, $period->id);

                DB::beginTransaction();
                $original = (new PostJournalEntry)->execute($owner, $this->draft($owner, $account, '2025-12-15')->id);
                $this->conflict(fn () => (new ReverseJournalEntry)->execute($owner, $original->id));
                $this->assertSame(1, JournalEntry::ownedBy($owner)->count());
                DB::rollBack();
            });
        } finally {
            $this->travelBack();
        }
    }
}
