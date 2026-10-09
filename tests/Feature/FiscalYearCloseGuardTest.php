<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\ReopenAccountingPeriod;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Actions\UpdateOpeningBalanceDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\PeriodGuard;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FiscalYearCloseGuardTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function account(User $owner, string $code, string $type): ChartAccount
    {
        $account = (new CreateChartAccount)->execute($owner, $code, $code, $type);
        $account->update(['cash_role' => 'non_cash']);

        return $account;
    }

    private function close(User $owner, ChartAccount $retained, string $start = '2026-04-01', string $end = '2027-03-31', ?JournalEntry $journal = null): int
    {
        return DB::table('fiscal_year_closes')->insertGetId([
            'user_id' => $owner->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => config('accounting.currency'), 'retained_earnings_account_id' => $retained->id,
            'journal_entry_id' => $journal?->id, 'closed_at' => now(),
        ]);
    }

    private function lines(ChartAccount $debit, ChartAccount $credit): array
    {
        return [
            ['chart_account_id' => $debit->id, 'debit' => '0.01', 'credit' => '0.00'],
            ['chart_account_id' => $credit->id, 'debit' => '0.00', 'credit' => '0.01'],
        ];
    }

    private function draft(User $owner, ChartAccount $debit, ChartAccount $credit, string $date = '2026-06-15'): JournalEntry
    {
        return (new SaveJournalDraft)->execute($owner, $date, config('accounting.currency'), $this->lines($debit, $credit));
    }

    private function openingDraft(User $owner, ChartAccount $debit, ChartAccount $credit, string $date = '2026-06-15'): OpeningBalanceBatch
    {
        return (new CreateOpeningBalanceDraft)->execute($owner, $date, config('accounting.currency'), $this->lines($debit, $credit));
    }

    private function conflict(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected an accounting conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_period_guard_distinguishes_closed_period_from_permanent_fiscal_year(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $retained = $this->account($owner, '3000', 'equity');
        $open = (new CreateAccountingPeriod)->execute($owner, '2026-04-01', '2027-03-31');
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            (new PeriodGuard)->assertOpen($owner, '2026-04-01');
        });
        $this->close($owner, $retained);
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-04-01'), 'Fiscal year');
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2027-03-31'), 'Fiscal year');
            (new PeriodGuard)->assertOpen($owner, '2027-04-01');
        });
        (new CloseAccountingPeriod)->execute($owner, $open->id);
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-06-15'), 'Accounting period');
        });
        (new ReopenAccountingPeriod)->execute($owner, $open->id);
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-06-15'), 'Fiscal year');
        });

        $this->close($other, $this->account($other, '3000', 'equity'), '2028-01-01', '2028-12-31');
        DB::transaction(function () use ($owner, $other): void {
            AccountingPeriodLocks::owner($owner);
            (new PeriodGuard)->assertOpen($owner, '2028-06-15');
        });
        DB::transaction(function () use ($other): void {
            AccountingPeriodLocks::owner($other);
            (new PeriodGuard)->assertOpen($other, '2026-06-15');
        });
    }

    public function test_fiscal_year_guard_applies_without_any_accounting_period_and_locks_rows(): void
    {
        $owner = User::factory()->create();
        $this->close($owner, $this->account($owner, '3000', 'equity'));
        $queries = [];
        DB::listen(function ($event) use (&$queries): void { $queries[] = $event->sql; });
        DB::transaction(function () use ($owner): void {
            AccountingPeriodLocks::owner($owner);
            $this->conflict(fn () => (new PeriodGuard)->assertOpen($owner, '2026-06-15'), 'Fiscal year');
        });
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains(strtolower($sql), 'fiscal_year_closes')
            && str_contains(strtolower($sql), 'for update')));
    }

    public function test_journal_draft_save_and_post_are_blocked_but_posted_retry_remains_idempotent(): void
    {
        $owner = User::factory()->create();
        $asset = $this->account($owner, '1000', 'asset');
        $retained = $this->account($owner, '3000', 'equity');
        $draft = $this->draft($owner, $asset, $retained);
        $posted = (new PostJournalEntry)->execute($owner, $this->draft($owner, $asset, $retained)->id);
        $this->close($owner, $retained);
        $this->conflict(fn () => $this->draft($owner, $asset, $retained), 'Fiscal year');
        $this->conflict(fn () => (new SaveJournalDraft)->execute($owner, '2026-06-15', config('accounting.currency'), $this->lines($asset, $retained), journalId: $draft->id, version: $draft->version), 'Fiscal year');
        $this->conflict(fn () => (new PostJournalEntry)->execute($owner, $draft->id), 'Fiscal year');
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame($posted->id, (new PostJournalEntry)->execute($owner, $posted->id)->id);
        $moved = (new SaveJournalDraft)->execute($owner, '2027-04-01', config('accounting.currency'), $this->lines($asset, $retained), journalId: $draft->id, version: $draft->version);
        $this->assertSame('2027-04-01', $moved->entry_date->toDateString());
    }

    public function test_opening_balance_save_and_post_are_blocked_but_posted_retry_remains_idempotent(): void
    {
        $owner = User::factory()->create();
        $asset = $this->account($owner, '1000', 'asset');
        $retained = $this->account($owner, '3000', 'equity');
        $draft = $this->openingDraft($owner, $asset, $retained);
        $posted = (new PostOpeningBalanceBatch)->execute($owner, $this->openingDraft($owner, $asset, $retained)->id);
        $this->close($owner, $retained);
        $this->conflict(fn () => $this->openingDraft($owner, $asset, $retained), 'Fiscal year');
        $this->conflict(fn () => (new UpdateOpeningBalanceDraft)->execute($owner, $draft->id, '2026-06-15', config('accounting.currency'), $this->lines($asset, $retained)), 'Fiscal year');
        $this->conflict(fn () => (new PostOpeningBalanceBatch)->execute($owner, $draft->id), 'Fiscal year');
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertSame($posted->journal_entry_id, (new PostOpeningBalanceBatch)->execute($owner, $posted->id)->journal_entry_id);
        $moved = (new UpdateOpeningBalanceDraft)->execute($owner, $draft->id, '2027-04-01', config('accounting.currency'), $this->lines($asset, $retained));
        $this->assertSame('2027-04-01', $moved->opening_date->toDateString());
    }

    public function test_reversal_date_in_closed_fiscal_year_is_blocked_without_a_partial_reversal(): void
    {
        $owner = User::factory()->create();
        $asset = $this->account($owner, '1000', 'asset');
        $retained = $this->account($owner, '3000', 'equity');
        $original = (new PostJournalEntry)->execute($owner, $this->draft($owner, $asset, $retained, '2026-03-15')->id);
        $this->close($owner, $retained);
        $this->travelTo(\Carbon\Carbon::parse('2026-06-15'));
        try {
            $this->conflict(fn () => (new ReverseJournalEntry)->execute($owner, $original->id), 'Fiscal year');
        } finally {
            $this->travelBack();
        }
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertNull($original->fresh()->reversal);
    }

    public function test_closing_journal_cannot_be_generically_reversed_but_ordinary_and_opening_journals_can(): void
    {
        $owner = User::factory()->create();
        $asset = $this->account($owner, '1000', 'asset');
        $retained = $this->account($owner, '3000', 'equity');
        $closingJournal = (new PostJournalEntry)->execute($owner, $this->draft($owner, $asset, $retained, '2026-03-15')->id);
        $ordinary = (new PostJournalEntry)->execute($owner, $this->draft($owner, $asset, $retained, '2026-03-16')->id);
        $opening = (new PostOpeningBalanceBatch)->execute($owner, $this->openingDraft($owner, $asset, $retained, '2026-03-17')->id);
        $this->close($owner, $retained, '2026-01-01', '2026-03-31', $closingJournal);
        $this->assertFalse(Gate::forUser($owner)->allows('reverse', $closingJournal));
        $this->assertTrue(Gate::forUser($owner)->allows('reverse', $ordinary));
        $this->assertTrue(Gate::forUser($owner)->allows('reverse', $opening->journalEntry));
        $this->travelTo(\Carbon\Carbon::parse('2026-04-15'));
        try {
            $this->conflict(fn () => (new ReverseJournalEntry)->execute($owner, $closingJournal->id), 'Fiscal-year closing');
            $this->assertTrue((new ReverseJournalEntry)->execute($owner, $ordinary->id)->isPosted());
            $this->assertTrue((new ReverseJournalEntry)->execute($owner, $opening->journal_entry_id)->isPosted());
        } finally {
            $this->travelBack();
        }
        $this->assertDatabaseCount('journal_entries', 5);
        $this->assertNull($closingJournal->fresh()->reversal);
        $this->assertTrue($opening->fresh()->isPosted());
    }

    public function test_retained_earnings_account_reference_blocks_delete_and_structural_change(): void
    {
        $owner = User::factory()->create();
        $retained = $this->account($owner, '3000', 'equity');
        $this->close($owner, $retained);
        $this->conflict(fn () => (new DeleteChartAccount)->execute($owner, $retained->id), 'Retained earnings');
        $this->conflict(fn () => (new UpdateChartAccount)->execute($owner, $retained->id, '3100', 'Renamed', 'equity', true), 'Referenced');
        $this->conflict(fn () => (new UpdateChartAccount)->execute($owner, $retained->id, '3000', 'Renamed', 'asset', true), 'Referenced');
        $renamed = (new UpdateChartAccount)->execute($owner, $retained->id, '3000', 'Renamed', 'equity', false);
        $this->assertSame('Renamed', $renamed->name);
        $this->assertFalse($renamed->is_active);
    }
}
