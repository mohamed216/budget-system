<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseFiscalYear;
use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class FiscalYearCloseActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private ChartAccount $asset;
    private ChartAccount $retained;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->asset = $this->account('1000', 'asset');
        $this->retained = $this->account('3000', 'equity');
    }

    private function account(string $code, string $type, ?User $owner = null): ChartAccount
    {
        return (new CreateChartAccount)->execute($owner ?? $this->owner, $code, $code, $type);
    }

    private function line(ChartAccount $account, string $debit, string $credit): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit];
    }

    private function draft(array $lines, string $date = '2026-06-15', ?User $owner = null): JournalEntry
    {
        return (new SaveJournalDraft)->execute($owner ?? $this->owner, $date, config('accounting.currency'), $lines);
    }

    private function posted(array $lines, string $date = '2026-06-15', ?User $owner = null): JournalEntry
    {
        $actor = $owner ?? $this->owner;

        return (new PostJournalEntry)->execute($actor, $this->draft($lines, $date, $actor)->id);
    }

    private function close(string $start = '2026-01-01', string $end = '2026-12-31', ?User $owner = null, ?int $retainedId = null, ?string $currency = null): \App\Models\FiscalYearClose
    {
        return (new CloseFiscalYear)->execute($owner ?? $this->owner, $start, $end,
            $currency ?? config('accounting.currency'), $retainedId ?? $this->retained->id);
    }

    private function conflict(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected accounting conflict.');
        } catch (AccountingConflict $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_profitable_year_creates_exact_posted_immutable_closing_journal(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->posted([$this->line($this->asset, '100.01', '0.00'), $this->line($revenue, '0.00', '100.01')], '2026-01-01');
        $this->posted([$this->line($expense, '40.00', '0.00'), $this->line($this->asset, '0.00', '40.00')], '2026-12-31');
        $close = $this->close();
        $journal = $close->journalEntry;
        $this->assertSame($this->owner->id, $close->user_id);
        $this->assertSame($journal->id, $close->journal_entry_id);
        $this->assertSame('2026-12-31', $journal->entry_date->toDateString());
        $this->assertSame(config('accounting.currency'), $journal->currency);
        $this->assertTrue($journal->isPosted());
        $this->assertSame(2, $journal->version);
        $this->assertNotNull($close->closed_at);
        $this->assertSame([
            [$revenue->id, '100.01', '0.00'], [$expense->id, '0.00', '40.00'],
            [$this->retained->id, '0.00', '60.01'],
        ], $journal->lines->map(fn ($line) => [$line->chart_account_id, $line->debit, $line->credit])->all());
        $this->assertSame([1, 2, 3], $journal->lines->pluck('line_number')->all());
        $this->assertFalse(Gate::forUser($this->owner)->allows('reverse', $journal));
        $this->travelTo(\Carbon\Carbon::parse('2027-01-01'));
        try {
            $this->conflict(fn () => (new ReverseJournalEntry)->execute($this->owner, $journal->id), 'Fiscal-year closing');
        } finally {
            $this->travelBack();
        }
        try {
            DB::table('journal_entries')->where('id', $journal->id)->update(['reference' => 'changed']);
            $this->fail('Posted closing journal must be immutable.');
        } catch (QueryException $exception) {
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
    }

    public function test_loss_and_contra_balances_close_on_the_correct_sides(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->posted([$this->line($revenue, '20.00', '0.00'), $this->line($this->asset, '0.00', '20.00')]);
        $this->posted([$this->line($this->asset, '5.00', '0.00'), $this->line($expense, '0.00', '5.00')]);
        $lines = $this->close()->journalEntry->lines;
        $this->assertSame([
            [$revenue->id, '0.00', '20.00'], [$expense->id, '5.00', '0.00'],
            [$this->retained->id, '15.00', '0.00'],
        ], $lines->map(fn ($line) => [$line->chart_account_id, $line->debit, $line->credit])->all());
    }

    public function test_normal_expense_loss_debits_retained_earnings(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->posted([$this->line($this->asset, '10.00', '0.00'), $this->line($revenue, '0.00', '10.00')]);
        $this->posted([$this->line($expense, '12.00', '0.00'), $this->line($this->asset, '0.00', '12.00')]);
        $lines = $this->close()->journalEntry->lines;
        $this->assertSame([
            [$revenue->id, '10.00', '0.00'], [$expense->id, '0.00', '12.00'],
            [$this->retained->id, '2.00', '0.00'],
        ], $lines->map(fn ($line) => [$line->chart_account_id, $line->debit, $line->credit])->all());
    }

    public function test_multiple_accounts_inactive_history_zero_profit_and_exact_maximum(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $contra = $this->account('4001', 'revenue');
        $expense = $this->account('5000', 'expense');
        $this->posted([$this->line($this->asset, '100.01', '0.00'), $this->line($revenue, '0.00', '100.01')]);
        $this->posted([$this->line($contra, '0.01', '0.00'), $this->line($this->asset, '0.00', '0.01')]);
        $this->posted([$this->line($expense, '100.00', '0.00'), $this->line($this->asset, '0.00', '100.00')]);
        $revenue->is_active = false;
        $revenue->save();
        $lines = $this->close()->journalEntry->lines;
        $this->assertCount(3, $lines);
        $this->assertSame([$revenue->id, $contra->id, $expense->id], $lines->pluck('chart_account_id')->all());
        $this->assertSame(['100.01', '0.00', '0.00'], $lines->pluck('debit')->all());
        $this->assertSame(['0.00', '0.01', '100.00'], $lines->pluck('credit')->all());

        $other = User::factory()->create();
        $asset = $this->account('1000', 'asset', $other);
        $retained = $this->account('3000', 'equity', $other);
        $largeRevenue = $this->account('4000', 'revenue', $other);
        $this->posted([$this->line($asset, '9999999999999.99', '0.00'), $this->line($largeRevenue, '0.00', '9999999999999.99')], '2027-01-01', $other);
        $largeClose = $this->close('2027-01-01', '2027-12-31', $other, $retained->id);
        $this->assertSame(['9999999999999.99', '0.00'], $largeClose->journalEntry->lines->pluck('debit')->all());
        $this->assertSame(['0.00', '9999999999999.99'], $largeClose->journalEntry->lines->pluck('credit')->all());
    }

    public function test_zero_activity_adjacent_ranges_overlap_and_owner_isolation(): void
    {
        $first = $this->close();
        $this->assertNull($first->journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
        $this->conflict(fn () => $this->close(), 'overlaps');
        $this->conflict(fn () => $this->close('2026-12-31', '2027-12-31'), 'overlaps');
        $this->assertNotNull($this->close('2027-01-01', '2027-12-31'));
        $other = User::factory()->create();
        $retained = $this->account('3000', 'equity', $other);
        $this->assertNull($this->close(owner: $other, retainedId: $retained->id)->journal_entry_id);
        $this->assertDatabaseCount('fiscal_year_closes', 3);
    }

    public function test_drafts_block_close_but_posted_journals_and_opening_balances_do_not(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $draft = $this->draft([$this->line($this->asset, '1.00', '0.00'), $this->line($revenue, '0.00', '1.00')]);
        $this->conflict(fn () => $this->close(), 'Draft journals');
        (new PostJournalEntry)->execute($this->owner, $draft->id);
        $opening = (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-07-01', config('accounting.currency'), [
            $this->line($this->asset, '2.00', '0.00'), $this->line($this->retained, '0.00', '2.00'),
        ]);
        $this->conflict(fn () => $this->close(), 'Draft opening balances');
        (new PostOpeningBalanceBatch)->execute($this->owner, $opening->id);
        $this->assertNotNull($this->close()->journal_entry_id);
    }

    public function test_retained_account_and_currency_are_validated_without_cross_owner_leakage(): void
    {
        $other = User::factory()->create();
        $foreign = $this->account('3000', 'equity', $other);
        $asset = $this->asset;
        $inactive = $this->account('3001', 'equity');
        $inactive->is_active = false;
        $inactive->save();
        foreach ([$foreign->id, $asset->id, $inactive->id, 999999] as $id) {
            $this->conflict(fn () => $this->close(retainedId: $id), 'Retained earnings');
        }
        try {
            $this->close(currency: 'USD' === config('accounting.currency') ? 'SAR' : 'USD');
            $this->fail('Wrong configured currency must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency', $exception->errors());
        }
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }

    public function test_posted_historical_currency_mismatch_is_rejected(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $this->posted([$this->line($this->asset, '1.00', '0.00'), $this->line($revenue, '0.00', '1.00')]);
        config(['accounting.currency' => config('accounting.currency') === 'SAR' ? 'USD' : 'SAR']);
        $this->conflict(fn () => $this->close(), 'currency');
        $this->assertDatabaseCount('fiscal_year_closes', 0);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_failure_after_closing_journal_line_rolls_back_everything(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $this->posted([$this->line($this->asset, '1.00', '0.00'), $this->line($revenue, '0.00', '1.00')]);
        Event::listen('eloquent.created: '.JournalLine::class, fn () => throw new RuntimeException('Injected closing-line failure'));
        try {
            try {
                $this->close();
                $this->fail('Injected failure must abort close.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected closing-line failure', $exception->getMessage());
            }
        } finally {
            Event::forget('eloquent.created: '.JournalLine::class);
        }
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }

    public function test_failure_after_posting_closing_journal_also_rolls_back_everything(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $this->posted([$this->line($this->asset, '1.00', '0.00'), $this->line($revenue, '0.00', '1.00')]);
        Event::listen('eloquent.created: '.\App\Models\FiscalYearClose::class, fn () => throw new RuntimeException('Injected close-row failure'));
        try {
            try {
                $this->close();
                $this->fail('Injected failure must abort close.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Injected close-row failure', $exception->getMessage());
            }
        } finally {
            Event::forget('eloquent.created: '.\App\Models\FiscalYearClose::class);
        }
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertDatabaseCount('fiscal_year_closes', 0);
    }

    public function test_lock_order_is_owner_period_fiscal_year_drafts_posted_lines_accounts_retained(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $this->posted([$this->line($this->asset, '1.00', '0.00'), $this->line($revenue, '0.00', '1.00')]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->close();
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'for update')));
            $this->assertCount(9, $locks);
            foreach (['users', 'accounting_periods', 'fiscal_year_closes', 'journal_entries',
                'opening_balance_batches', 'journal_entries', 'journal_lines', 'chart_of_accounts'] as $index => $table) {
                $this->assertStringContainsString($table, $locks[$index]['query']);
            }
            $this->assertStringContainsString('order by `id` asc', $locks[7]['query']);
            $this->assertStringContainsString('chart_of_accounts', $locks[8]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /** Publish only disposable draft fixtures; no posted journal is committed by these races. */
    private function raceFixture(callable $scenario): void
    {
        $originalConnection = DB::getDefaultConnection();
        $ownerId = $this->owner->id;
        config(['database.connections.fiscal_close_probe' => config('database.connections.mysql_testing')]);
        $probe = DB::connection('fiscal_close_probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::commit();
        try {
            $scenario();
        } finally {
            DB::setDefaultConnection($originalConnection);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('fiscal_year_closes')->where('user_id', $ownerId)->delete();
            DB::table('opening_balance_lines')->where('user_id', $ownerId)->delete();
            DB::table('opening_balance_batches')->where('user_id', $ownerId)->delete();
            DB::table('journal_lines')->where('user_id', $ownerId)->delete();
            DB::table('journal_entries')->where('user_id', $ownerId)->delete();
            DB::table('chart_of_accounts')->where('user_id', $ownerId)->delete();
            DB::table('users')->where('id', $ownerId)->delete();
            DB::beginTransaction();
            DB::disconnect('fiscal_close_probe');
        }
    }

    private function assertProbeWaits(callable $operation): void
    {
        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('fiscal_close_probe');
        try {
            $operation();
            $this->fail('The second connection must wait for the owner row.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[1], [1205, 3572]);
        } finally {
            DB::setDefaultConnection($originalConnection);
        }
    }

    public function test_concurrent_same_and_overlapping_closes_serialize_and_only_one_commits(): void
    {
        $this->raceFixture(function (): void {
            DB::beginTransaction();
            $first = $this->close();
            $this->assertProbeWaits(fn () => $this->close());
            $this->assertProbeWaits(fn () => $this->close('2026-12-31', '2027-12-31'));
            DB::commit();
            $this->conflict(fn () => $this->close(), 'overlaps');
            $this->conflict(fn () => $this->close('2026-12-31', '2027-12-31'), 'overlaps');
            $this->assertDatabaseCount('fiscal_year_closes', 1);
            $this->assertSame($first->id, DB::table('fiscal_year_closes')->value('id'));
        });
    }

    public function test_close_first_serializes_journal_and_opening_balance_draft_saves(): void
    {
        $this->raceFixture(function (): void {
            DB::beginTransaction();
            $this->close();
            $this->assertProbeWaits(fn () => $this->draft([
                $this->line($this->asset, '1.00', '0.00'), $this->line($this->retained, '0.00', '1.00'),
            ]));
            $this->assertProbeWaits(fn () => (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-06-15', config('accounting.currency'), [
                $this->line($this->asset, '1.00', '0.00'), $this->line($this->retained, '0.00', '1.00'),
            ]));
            DB::commit();
            $this->conflict(fn () => $this->draft([
                $this->line($this->asset, '1.00', '0.00'), $this->line($this->retained, '0.00', '1.00'),
            ]), 'Fiscal year');
            $this->conflict(fn () => (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-06-15', config('accounting.currency'), [
                $this->line($this->asset, '1.00', '0.00'), $this->line($this->retained, '0.00', '1.00'),
            ]), 'Fiscal year');
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('opening_balance_batches', 0);
        });
    }

    public function test_journal_post_first_serializes_close_and_leaves_draft_if_post_rolls_back(): void
    {
        $revenue = $this->account('4000', 'revenue');
        $draft = $this->draft([$this->line($this->asset, '1.00', '0.00'), $this->line($revenue, '0.00', '1.00')]);
        $this->raceFixture(function () use ($draft): void {
            DB::beginTransaction();
            (new PostJournalEntry)->execute($this->owner, $draft->id);
            $this->assertProbeWaits(fn () => $this->close());
            DB::rollBack();
            $this->conflict(fn () => $this->close(), 'Draft journals');
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertDatabaseCount('fiscal_year_closes', 0);
        });
    }

    public function test_opening_balance_post_first_serializes_close_and_leaves_draft_if_post_rolls_back(): void
    {
        $batch = (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-06-15', config('accounting.currency'), [
            $this->line($this->asset, '1.00', '0.00'), $this->line($this->retained, '0.00', '1.00'),
        ]);
        $this->raceFixture(function () use ($batch): void {
            DB::beginTransaction();
            (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
            $this->assertProbeWaits(fn () => $this->close());
            DB::rollBack();
            $this->conflict(fn () => $this->close(), 'Draft opening balances');
            $this->assertSame('draft', $batch->fresh()->status);
            $this->assertDatabaseCount('fiscal_year_closes', 0);
        });
    }
}
