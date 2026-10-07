<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\CreateOpeningBalanceDraft;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\DeleteOpeningBalanceDraft;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\UpdateOpeningBalanceDraft;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\JournalLine;
use App\Models\OpeningBalanceBatch;
use App\Models\OpeningBalanceLine;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OpeningBalanceActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private ChartAccount $asset;
    private ChartAccount $equity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->asset = $this->account($this->owner, '1000', 'asset');
        $this->equity = $this->account($this->owner, '3000', 'equity');
    }

    private function account(User $owner, string $code, string $type): ChartAccount
    {
        return $owner->chartAccounts()->create([
            'code' => $code, 'name' => $code, 'type' => $type, 'is_active' => true,
        ]);
    }

    private function lines(string $amount = '0.01'): array
    {
        return [
            ['chart_account_id' => $this->asset->id, 'debit' => $amount, 'credit' => '0'],
            ['chart_account_id' => $this->equity->id, 'debit' => '0', 'credit' => $amount],
        ];
    }

    private function draft(string $date = '2026-01-15', ?array $lines = null): OpeningBalanceBatch
    {
        return (new CreateOpeningBalanceDraft)->execute($this->owner, $date, config('accounting.currency'), $lines ?? $this->lines());
    }

    private function closed(string $start, string $end): void
    {
        $period = (new CreateAccountingPeriod)->execute($this->owner, $start, $end);
        (new CloseAccountingPeriod)->execute($this->owner, $period->id);
    }

    private function rejects(callable $operation, string $exceptionClass): void
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

    private function snapshot(OpeningBalanceBatch $batch): array
    {
        return [
            (array) DB::table('opening_balance_batches')->where('id', $batch->id)->first(),
            DB::table('opening_balance_lines')->where('batch_id', $batch->id)->orderBy('id')->get()->map(fn ($line) => (array) $line)->all(),
        ];
    }

    public function test_create_update_and_delete_draft_replace_lines_atomically(): void
    {
        $batch = $this->draft();
        $this->assertTrue($batch->isDraft());
        $this->assertSame($this->owner->id, $batch->user_id);
        $this->assertNull($batch->journal_entry_id);
        $this->assertNull($batch->posted_at);
        $this->assertSame(['0.01', '0.00'], $batch->lines->pluck('debit')->all());
        $oldIds = $batch->lines->pluck('id')->all();

        $updated = (new UpdateOpeningBalanceDraft)->execute($this->owner, $batch->id, '2026-02-01', config('accounting.currency'), $this->lines('2.25'));
        $this->assertSame('2026-02-01', $updated->opening_date->toDateString());
        $this->assertSame(['2.25', '0.00'], $updated->lines->pluck('debit')->all());
        foreach ($oldIds as $id) {
            $this->assertDatabaseMissing('opening_balance_lines', ['id' => $id]);
        }
        $this->assertDatabaseCount('opening_balance_batches', 1);
        $this->assertDatabaseCount('opening_balance_lines', 2);
        (new DeleteOpeningBalanceDraft)->execute($this->owner, $batch->id);
        $this->assertDatabaseCount('opening_balance_batches', 0);
        $this->assertDatabaseCount('opening_balance_lines', 0);
    }

    public function test_invalid_lines_foreign_accounts_inactive_accounts_and_currency_leave_no_rows(): void
    {
        $foreign = $this->account(User::factory()->create(), '9000', 'asset');
        $cases = [
            [],
            [$this->lines()[0]],
            [$this->lines()[0], $this->lines()[0]],
            [['chart_account_id' => $this->asset->id, 'debit' => '0', 'credit' => '0'], $this->lines()[1]],
            [['chart_account_id' => $this->asset->id, 'debit' => '1', 'credit' => '1'], $this->lines()[1]],
            [['chart_account_id' => $this->asset->id, 'debit' => 0.01, 'credit' => '0'], $this->lines()[1]],
            [['chart_account_id' => $this->asset->id, 'debit' => '1.01', 'credit' => '0'], $this->lines()[1]],
            [['chart_account_id' => $foreign->id, 'debit' => '1', 'credit' => '0'], $this->lines()[1]],
        ];
        foreach ($cases as $lines) {
            $this->rejects(fn () => $this->draft(lines: $lines), ValidationException::class);
        }
        $this->asset->update(['is_active' => false]);
        $this->rejects(fn () => $this->draft(), ValidationException::class);
        $this->asset->update(['is_active' => true]);
        $this->rejects(fn () => (new CreateOpeningBalanceDraft)->execute($this->owner, '2026-01-15', 'USD' === config('accounting.currency') ? 'SAR' : 'USD', $this->lines()), ValidationException::class);
        $this->assertDatabaseCount('opening_balance_batches', 0);
        $this->assertDatabaseCount('opening_balance_lines', 0);
    }

    public function test_update_validation_and_injected_line_failure_preserve_original_draft(): void
    {
        $batch = $this->draft();
        $before = $this->snapshot($batch);
        $update = new UpdateOpeningBalanceDraft;
        $this->rejects(fn () => $update->execute($this->owner, $batch->id, '2026-02-01', config('accounting.currency'), [$this->lines()[0]]), ValidationException::class);
        $this->assertSame($before, $this->snapshot($batch));

        Event::listen('eloquent.created: '.OpeningBalanceLine::class, fn () => throw new RuntimeException('Injected line failure'));
        try {
            $this->rejects(fn () => $update->execute($this->owner, $batch->id, '2026-02-01', config('accounting.currency'), $this->lines('2.00')), RuntimeException::class);
        } finally {
            Event::forget('eloquent.created: '.OpeningBalanceLine::class);
        }
        $this->assertSame($before, $this->snapshot($batch));
    }

    public function test_delete_failure_rolls_back_removed_draft_lines(): void
    {
        $batch = $this->draft();
        $before = $this->snapshot($batch);
        Event::listen('eloquent.deleting: '.OpeningBalanceBatch::class, fn () => throw new RuntimeException('Injected batch deletion failure'));
        try {
            $this->rejects(fn () => (new DeleteOpeningBalanceDraft)->execute($this->owner, $batch->id), RuntimeException::class);
        } finally {
            Event::forget('eloquent.deleting: '.OpeningBalanceBatch::class);
        }
        $this->assertSame($before, $this->snapshot($batch));
    }

    public function test_foreign_and_missing_batches_are_not_found_without_mutation(): void
    {
        $batch = $this->draft();
        $other = User::factory()->create();
        foreach ([$other, $this->owner] as $actor) {
            $id = $actor->is($other) ? $batch->id : $batch->id + 1000;
            $this->rejects(fn () => (new UpdateOpeningBalanceDraft)->execute($actor, $id, '2026-02-01', config('accounting.currency'), $this->lines()), ModelNotFoundException::class);
            $this->rejects(fn () => (new DeleteOpeningBalanceDraft)->execute($actor, $id), ModelNotFoundException::class);
            $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($actor, $id), ModelNotFoundException::class);
        }
        $this->assertTrue($batch->fresh()->isDraft());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_referenced_chart_account_has_domain_conflicts_for_delete_or_code_change(): void
    {
        $batch = $this->draft();
        $this->rejects(fn () => (new DeleteChartAccount)->execute($this->owner, $this->asset->id), AccountingConflict::class);
        $this->rejects(fn () => (new UpdateChartAccount)->execute($this->owner, $this->asset->id, '1001', 'Cash', 'asset', true), AccountingConflict::class);
        $this->assertNotNull($batch->fresh());
        $this->assertSame('1000', $this->asset->fresh()->code);
    }

    public function test_closed_period_blocks_create_and_save_but_allows_move_and_stranded_delete(): void
    {
        $batch = $this->draft();
        $stranded = $this->draft();
        $this->closed('2026-01-01', '2026-01-31');
        $this->rejects(fn () => $this->draft(), AccountingConflict::class);
        $this->rejects(fn () => (new UpdateOpeningBalanceDraft)->execute($this->owner, $batch->id, '2026-01-15', config('accounting.currency'), $this->lines()), AccountingConflict::class);
        $this->assertSame('2026-01-15', $batch->fresh()->opening_date->toDateString());
        $moved = (new UpdateOpeningBalanceDraft)->execute($this->owner, $batch->id, '2026-02-01', config('accounting.currency'), $this->lines());
        $this->assertSame('2026-02-01', $moved->opening_date->toDateString());
        (new DeleteOpeningBalanceDraft)->execute($this->owner, $stranded->id);
        $this->assertDatabaseMissing('opening_balance_batches', ['id' => $stranded->id]);
    }

    public function test_post_creates_one_exact_posted_journal_with_matching_metadata(): void
    {
        $batch = $this->draft(lines: $this->lines('9999999999999.99'));
        $before = $this->snapshot($batch);
        $posted = (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
        $journal = $posted->journalEntry;
        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);
        $this->assertTrue($journal->isPosted());
        $this->assertSame(2, $journal->version);
        $this->assertSame($this->owner->id, $journal->user_id);
        $this->assertSame('2026-01-15', $journal->entry_date->toDateString());
        $this->assertSame($batch->currency, $journal->currency);
        $this->assertSame('OB-'.$batch->id, $journal->reference);
        $this->assertStringContainsString('#'.$batch->id, $journal->description);
        $this->assertSame($journal->id, $posted->journal_entry_id);
        $this->assertSame($posted->posted_at->format('Y-m-d H:i:s.u'), $journal->posted_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(['9999999999999.99', '0.00'], $journal->lines->pluck('debit')->all());
        $this->assertSame(['0.00', '9999999999999.99'], $journal->lines->pluck('credit')->all());
        $this->assertSame([$this->asset->id, $this->equity->id], $journal->lines->pluck('chart_account_id')->all());
        $this->assertSame([1, 2], $journal->lines->pluck('line_number')->all());
        $this->assertSame($before[1], $this->snapshot($batch)[1]);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        try {
            DB::table('journal_entries')->where('id', $journal->id)->update(['reference' => 'changed']);
            $this->fail('Generated posted journal must use existing immutability protection.');
        } catch (QueryException $exception) {
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
    }

    public function test_post_rechecks_persisted_date_accounts_and_currency_without_partial_journal(): void
    {
        $batch = $this->draft();
        $this->closed('2026-01-01', '2026-01-31');
        $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id), AccountingConflict::class);
        $this->assertTrue($batch->fresh()->isDraft());
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);

        $other = $this->draft('2026-02-01');
        $this->asset->update(['is_active' => false]);
        $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($this->owner, $other->id), AccountingConflict::class);
        $this->asset->update(['is_active' => true]);
        config(['accounting.currency' => 'USD' === config('accounting.currency') ? 'SAR' : 'USD']);
        $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($this->owner, $other->id), AccountingConflict::class);
        $this->assertTrue($other->fresh()->isDraft());
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_post_retry_is_idempotent_even_after_period_close_and_account_deactivation(): void
    {
        $batch = $this->draft();
        $post = new PostOpeningBalanceBatch;
        $posted = $post->execute($this->owner, $batch->id);
        $journalId = $posted->journal_entry_id;
        $timestamp = $posted->posted_at->format('Y-m-d H:i:s.u');
        $this->closed('2026-01-01', '2026-01-31');
        $this->asset->update(['is_active' => false]);
        $this->travel(1)->days();
        $retried = $post->execute($this->owner, $batch->id);
        $this->assertSame($journalId, $retried->journal_entry_id);
        $this->assertSame($timestamp, $retried->posted_at->format('Y-m-d H:i:s.u'));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->rejects(fn () => (new UpdateOpeningBalanceDraft)->execute($this->owner, $batch->id, '2026-02-01', config('accounting.currency'), $this->lines()), AccountingConflict::class);
        $this->rejects(fn () => (new DeleteOpeningBalanceDraft)->execute($this->owner, $batch->id), AccountingConflict::class);
    }

    public function test_posted_batch_and_lines_are_immutable_even_through_direct_sql(): void
    {
        $posted = (new PostOpeningBalanceBatch)->execute($this->owner, $this->draft()->id);
        $lineId = $posted->lines->first()->id;
        $before = $this->snapshot($posted);
        foreach ([
            fn () => DB::table('opening_balance_batches')->where('id', $posted->id)->update(['currency' => 'USD']),
            fn () => DB::table('opening_balance_batches')->where('id', $posted->id)->delete(),
            fn () => DB::table('opening_balance_lines')->insert(['user_id' => $this->owner->id, 'batch_id' => $posted->id,
                'chart_account_id' => $this->asset->id, 'debit' => '1.00', 'credit' => '0.00']),
            fn () => DB::table('opening_balance_lines')->where('id', $lineId)->update(['debit' => '2.00']),
            fn () => DB::table('opening_balance_lines')->where('id', $lineId)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Posted opening balance history must be immutable.');
            } catch (QueryException $exception) {
                $this->assertSame(1644, $exception->errorInfo[1]);
            }
        }
        $this->assertSame($before, $this->snapshot($posted));
    }

    public function test_post_failure_after_first_journal_line_rolls_back_header_and_batch_state(): void
    {
        $batch = $this->draft();
        $before = $this->snapshot($batch);
        Event::listen('eloquent.created: '.JournalLine::class, fn () => throw new RuntimeException('Injected journal line failure'));
        try {
            $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id), RuntimeException::class);
        } finally {
            Event::forget('eloquent.created: '.JournalLine::class);
        }
        $this->assertSame($before, $this->snapshot($batch));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_failure_after_batch_status_update_rolls_back_posted_journal(): void
    {
        $batch = $this->draft();
        $before = $this->snapshot($batch);
        Event::listen('eloquent.updated: '.OpeningBalanceBatch::class, fn () => throw new RuntimeException('Injected batch transition failure'));
        try {
            $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id), RuntimeException::class);
        } finally {
            Event::forget('eloquent.updated: '.OpeningBalanceBatch::class);
        }
        $this->assertSame($before, $this->snapshot($batch));
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_post_lock_order_is_owner_period_batch_lines_accounts_then_journal(): void
    {
        $batch = $this->draft();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'for update')));
            $this->assertCount(6, $locks);
            foreach (['users', 'accounting_periods', 'opening_balance_batches', 'opening_balance_lines',
                'chart_of_accounts', 'journal_entries'] as $index => $table) {
                $this->assertStringContainsString($table, $locks[$index]['query']);
            }
            $this->assertStringContainsString('order by `id` asc', $locks[4]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_existing_reversal_of_generated_journal_leaves_batch_posted(): void
    {
        $posted = (new PostOpeningBalanceBatch)->execute($this->owner, $this->draft()->id);
        $before = $this->snapshot($posted);
        $reversal = (new ReverseJournalEntry)->execute($this->owner, $posted->journal_entry_id);
        $this->assertTrue($reversal->isPosted());
        $this->assertSame($posted->journal_entry_id, $reversal->reversal_of_id);
        $this->assertSame($before, $this->snapshot($posted));
        $this->assertTrue($posted->fresh()->isPosted());
    }

    public function test_owner_lock_serializes_double_post_and_retry_creates_no_second_journal(): void
    {
        $batch = $this->draft();
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.opening_balance_probe' => config('database.connections.mysql_testing')]);
        $probe = DB::connection('opening_balance_probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            DB::connection($originalConnection)->transaction(function () use ($batch, $originalConnection) {
                (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
                DB::setDefaultConnection('opening_balance_probe');
                try {
                    (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
                    $this->fail('Concurrent post must wait for the owner lock.');
                } catch (QueryException $exception) {
                    $this->assertContains($exception->errorInfo[1], [1205, 3572]);
                } finally {
                    DB::setDefaultConnection($originalConnection);
                }
            });
            $retry = (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id);
            $this->assertTrue($retry->isPosted());
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::disconnect('opening_balance_probe');
        }
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_corrupt_posted_link_is_rejected_on_retry_and_trigger_rollback_is_guarded(): void
    {
        $batch = $this->draft();
        $journalId = DB::table('journal_entries')->insertGetId([
            'user_id' => $this->owner->id, 'entry_date' => '2026-02-01', 'currency' => config('accounting.currency'),
        ]);
        DB::table('opening_balance_batches')->where('id', $batch->id)->update([
            'status' => 'posted', 'journal_entry_id' => $journalId, 'posted_at' => now(),
        ]);
        $this->rejects(fn () => (new PostOpeningBalanceBatch)->execute($this->owner, $batch->id), AccountingConflict::class);
        $migration = require database_path('migrations/2026_10_08_000003_protect_posted_opening_balances.php');
        $this->rejects(fn () => $migration->down(), RuntimeException::class);
    }
}
