<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class PostJournalEntryTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private ChartAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = (new CreateChartAccount)->execute($this->owner, '1000', 'Cash', 'asset');
    }

    private function line(string $debit, string $credit, ?ChartAccount $account = null): array
    {
        return ['chart_account_id' => ($account ?? $this->account)->id, 'debit' => $debit, 'credit' => $credit, 'description' => 'Unchanged line'];
    }

    private function draft(array $lines): JournalEntry
    {
        return (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), $lines, 'REF', 'Unchanged header');
    }

    private function snapshot(JournalEntry $journal): array
    {
        return [
            'header' => (array) DB::table('journal_entries')->where('id', $journal->id)->first(),
            'lines' => DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('line_number')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function rejectsUnchanged(JournalEntry $journal, string $exception = AccountingConflict::class, ?User $actor = null): void
    {
        $before = $this->snapshot($journal);
        try {
            (new PostJournalEntry)->execute($actor ?? $this->owner, $journal->id);
            $this->fail('Posting should be rejected.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
        $this->assertSame($before, $this->snapshot($journal));
        $this->assertTrue($journal->fresh()->isDraft());
        $this->assertNull($journal->fresh()->posted_at);
    }

    public function test_balanced_two_line_post_preserves_lines_header_fields_and_operational_balances(): void
    {
        $operational = $this->owner->accounts()->create(['name' => 'Operational', 'type' => 'cash', 'balance' => '123.45', 'currency' => 'SAR']);
        $journal = $this->draft([$this->line('12.34', '0'), $this->line('0', '12.34')]);
        $before = $this->snapshot($journal);
        $posted = (new PostJournalEntry)->execute($this->owner, $journal->id);
        $after = $this->snapshot($journal);
        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->posted_at);
        $this->assertSame(2, $posted->version);
        $this->assertSame($before['lines'], $after['lines']);
        foreach (['user_id', 'entry_date', 'currency', 'reference', 'description', 'created_at'] as $field) {
            $this->assertSame($before['header'][$field], $after['header'][$field]);
        }
        $this->assertSame('123.45', $operational->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_multiline_fractional_sums_and_repeated_accounts_post_exactly(): void
    {
        $journal = $this->draft([$this->line('0.10', '0'), $this->line('0.20', '0'), $this->line('0', '0.30')]);
        $before = $this->snapshot($journal);
        $this->assertTrue((new PostJournalEntry)->execute($this->owner, $journal->id)->isPosted());
        $this->assertSame($before['lines'], $this->snapshot($journal)['lines']);
    }

    public function test_large_exact_totals_exceed_native_integer_minor_units(): void
    {
        $journal = $this->draft([]);
        // 10,000 maximum amounts per side => 9,999,999,999,999,990,000 cents,
        // above PHP_INT_MAX on 64-bit PHP. Bulk fixtures keep this test practical.
        $batch = [];
        for ($number = 1; $number <= 20000; $number++) {
            $batch[] = ['user_id' => $this->owner->id, 'journal_entry_id' => $journal->id,
                'chart_account_id' => $this->account->id, 'line_number' => $number,
                'debit' => $number <= 10000 ? '9999999999999.99' : '0.00',
                'credit' => $number > 10000 ? '9999999999999.99' : '0.00'];
            if (count($batch) === 500) {
                DB::table('journal_lines')->insert($batch);
                $batch = [];
            }
        }
        $before = $this->snapshot($journal);
        $posted = (new PostJournalEntry)->execute($this->owner, $journal->id);
        $this->assertTrue($posted->isPosted());
        $this->assertCount(20000, $posted->lines);
        $this->assertSame($before['lines'], $this->snapshot($journal)['lines']);
    }

    public function test_empty_one_line_and_unbalanced_journals_are_rejected_unchanged(): void
    {
        foreach ([[], [$this->line('1', '0')], [$this->line('1', '0'), $this->line('0', '2')],
            [$this->line('1', '0'), $this->line('1', '0')]] as $lines) {
            $this->rejectsUnchanged($this->draft($lines));
        }
    }

    public function test_account_deactivated_after_draft_save_blocks_posting(): void
    {
        $journal = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
        $this->account->update(['is_active' => false]);
        $this->rejectsUnchanged($journal);
    }

    public function test_foreign_journal_is_not_found(): void
    {
        $journal = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
        $this->rejectsUnchanged($journal, ModelNotFoundException::class, User::factory()->create());
    }

    public function test_persisted_wrong_currency_is_rejected_case_sensitively(): void
    {
        foreach (['sar', 'USD'] as $currency) {
            $journal = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
            $journal->update(['currency' => $currency]);
            $this->rejectsUnchanged($journal);
        }
    }

    public function test_posting_retry_is_unchanged_even_if_account_is_now_inactive(): void
    {
        $journal = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
        $post = new PostJournalEntry;
        $posted = $post->execute($this->owner, $journal->id);
        $before = $this->snapshot($posted);
        $timestamp = $posted->posted_at->format('Y-m-d H:i:s.u');
        $this->account->update(['is_active' => false]);
        $this->travel(1)->days();
        $again = $post->execute($this->owner, $journal->id);
        $this->assertSame($timestamp, $again->posted_at->format('Y-m-d H:i:s.u'));
        $this->assertSame(2, $again->version);
        $this->assertSame($before, $this->snapshot($again));
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_posting_lock_order_uses_only_distinct_referenced_accounts_in_id_order(): void
    {
        $second = (new CreateChartAccount)->execute($this->owner, '1001', 'Revenue', 'revenue');
        $unrelated = (new CreateChartAccount)->execute($this->owner, '1002', 'Unused', 'expense');
        $journal = $this->draft([$this->line('1', '0', $second), $this->line('2', '0'), $this->line('0', '3', $second)]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new PostJournalEntry)->execute($this->owner, $journal->id);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')));
            $this->assertCount(5, $locks);
            $this->assertStringContainsString('users', $locks[0]['query']);
            $this->assertStringContainsString('accounting_periods', $locks[1]['query']);
            $this->assertStringContainsString('journal_entries', $locks[2]['query']);
            $this->assertStringContainsString('journal_lines', $locks[3]['query']);
            $this->assertStringContainsString('chart_of_accounts', $locks[4]['query']);
            $this->assertStringContainsString('order by `id` asc', $locks[4]['query']);
            $this->assertSame([$this->owner->id, $this->account->id, $second->id], $locks[4]['bindings']);
            $this->assertNotContains($unrelated->id, array_slice($locks[4]['bindings'], 1));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_failure_after_header_update_rolls_back_entire_transition(): void
    {
        $journal = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
        $event = 'eloquent.updated: '.JournalEntry::class;
        Event::listen($event, function (JournalEntry $entry) {
            $this->assertTrue($entry->isPosted());
            $this->assertSame('posted', DB::table('journal_entries')->where('id', $entry->id)->value('status'));
            throw new RuntimeException('Injected failure after persisted status mutation');
        });
        try {
            $this->rejectsUnchanged($journal, RuntimeException::class);
        } finally {
            Event::forget($event);
        }
    }

    public function test_schema_guards_prevent_zero_dual_sided_and_missing_account_fixtures(): void
    {
        $journal = $this->draft([]);
        $foreign = (new CreateChartAccount)->execute(User::factory()->create(), '1000', 'Foreign', 'asset');
        $missingId = ((int) DB::table('chart_of_accounts')->max('id')) + 1;
        $base = ['user_id' => $this->owner->id, 'journal_entry_id' => $journal->id,
            'chart_account_id' => $this->account->id, 'line_number' => 1, 'debit' => '1.00', 'credit' => '0.00'];
        $before = $this->snapshot($journal);
        foreach ([['debit' => '0.00'], ['credit' => '1.00'], ['debit' => '-1.00'],
            ['chart_account_id' => $missingId], ['chart_account_id' => $foreign->id]] as $changes) {
            try {
                DB::table('journal_lines')->insert(array_replace($base, $changes));
                $this->fail('Schema must reject this fixture without disabling constraints.');
            } catch (QueryException $error) {
                $this->assertContains($error->errorInfo[1], [3819, 1452]);
            }
            $this->assertSame($before, $this->snapshot($journal));
        }
        // Invalid persisted sides/missing references cannot safely be constructed here.
        // A zero-total empty draft is constructible and rejected by the action.
        $this->rejectsUnchanged($journal);
    }
}
