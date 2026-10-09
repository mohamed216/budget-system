<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class ReverseJournalEntryTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;
    private ChartAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->account = (new CreateChartAccount)->execute($this->owner, '1000', 'Cash', 'asset');
        $this->account->update(['cash_role' => 'non_cash']);
    }

    private function line(string $debit, string $credit, ?string $description = 'Original line', ?ChartAccount $account = null): array
    {
        return ['chart_account_id' => ($account ?? $this->account)->id, 'debit' => $debit, 'credit' => $credit, 'description' => $description];
    }

    private function draft(array $lines): JournalEntry
    {
        return (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), $lines, 'ORIG', 'Original journal');
    }

    private function posted(array $lines): JournalEntry
    {
        $draft = $this->draft($lines);

        return (new PostJournalEntry)->execute($this->owner, $draft->id);
    }

    private function snapshot(JournalEntry $entry): array
    {
        return [
            'header' => (array) DB::table('journal_entries')->where('id', $entry->id)->first(),
            'lines' => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->orderBy('line_number')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    public function test_successful_reversal_copies_every_line_and_preserves_original(): void
    {
        $otherAccount = (new CreateChartAccount)->execute($this->owner, '2000', 'Payable', 'liability');
        $otherAccount->update(['cash_role' => 'non_cash']);
        $original = $this->posted([
            $this->line('0.01', '0.00', 'First'),
            $this->line('9999999999999.99', '0.00', 'Maximum'),
            $this->line('0.00', '9999999999999.99', 'Third', $otherAccount),
            $this->line('0.00', '0.01', null, $otherAccount),
        ]);
        $before = $this->snapshot($original);
        $this->travelTo(now()->addDay());
        $reversal = (new ReverseJournalEntry)->execute($this->owner, $original->id);

        $this->assertSame($before, $this->snapshot($original));
        $this->assertNotSame($original->id, $reversal->id);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame($this->owner->id, $reversal->user_id);
        $this->assertSame($original->currency, $reversal->currency);
        $this->assertSame(now()->toDateString(), $reversal->entry_date->format('Y-m-d'));
        $this->assertSame('REV-'.$original->id, $reversal->reference);
        $this->assertStringContainsString('#'.$original->id, $reversal->description);
        $this->assertTrue($reversal->isPosted());
        $this->assertNotNull($reversal->posted_at);
        $this->assertSame(2, $reversal->version);
        $this->assertSame([1, 2, 3, 4], $reversal->lines->pluck('line_number')->all());
        $this->assertSame(['0.00', '0.00', '9999999999999.99', '0.01'], $reversal->lines->pluck('debit')->all());
        $this->assertSame(['0.01', '9999999999999.99', '0.00', '0.00'], $reversal->lines->pluck('credit')->all());
        $this->assertSame(['First', 'Maximum', 'Third', null], $reversal->lines->pluck('description')->all());
        $this->assertSame([$this->account->id, $this->account->id, $otherAccount->id, $otherAccount->id], $reversal->lines->pluck('chart_account_id')->all());
        $this->assertTrue($original->fresh()->reversal->is($reversal));
        $this->assertTrue($reversal->fresh()->reversalOf->is($original));
    }

    public function test_posted_reversal_header_and_lines_are_immutable(): void
    {
        $original = $this->posted([$this->line('1.00', '0.00'), $this->line('0.00', '1.00')]);
        $reversal = (new ReverseJournalEntry)->execute($this->owner, $original->id);
        $before = $this->snapshot($reversal);

        foreach ([
            fn () => DB::table('journal_entries')->where('id', $reversal->id)->update(['reference' => 'Edited']),
            fn () => DB::table('journal_entries')->where('id', $reversal->id)->delete(),
            fn () => DB::table('journal_lines')->where('id', $reversal->lines->first()->id)->update(['description' => 'Edited']),
            fn () => DB::table('journal_lines')->where('id', $reversal->lines->first()->id)->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Posted reversal must be immutable.');
            } catch (QueryException $exception) {
                $this->assertSame(1644, $exception->errorInfo[1]);
            }
        }
        $this->assertSame($before, $this->snapshot($reversal));
    }

    public function test_draft_reversal_of_reversal_and_duplicate_are_rejected(): void
    {
        $draft = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
        $original = $this->posted([$this->line('1', '0'), $this->line('0', '1')]);
        $action = new ReverseJournalEntry;

        foreach ([$draft->id] as $id) {
            try {
                $action->execute($this->owner, $id);
                $this->fail('Draft must not be reversed.');
            } catch (AccountingConflict $exception) {
                $this->assertStringContainsString('posted', $exception->getMessage());
            }
        }
        $reversal = $action->execute($this->owner, $original->id);
        foreach ([$original->id, $reversal->id] as $id) {
            $this->expectConflict(fn () => $action->execute($this->owner, $id));
        }
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseCount('journal_lines', 6);
    }

    public function test_foreign_owner_and_missing_entry_are_not_found(): void
    {
        $original = $this->posted([$this->line('1', '0'), $this->line('0', '1')]);
        $action = new ReverseJournalEntry;
        foreach ([[$other = User::factory()->create(), $original->id], [$this->owner, $original->id + 1000]] as [$actor, $id]) {
            try {
                $action->execute($actor, $id);
                $this->fail('Foreign or missing journal must not be found.');
            } catch (ModelNotFoundException) {
                $this->assertDatabaseCount('journal_entries', 1);
            }
        }
    }

    public function test_inactive_account_and_changed_configured_currency_do_not_block_reversal(): void
    {
        $original = $this->posted([$this->line('0.01', '0'), $this->line('0', '0.01')]);
        $this->account->update(['is_active' => false]);
        config(['accounting.currency' => 'USD']);

        $reversal = (new ReverseJournalEntry)->execute($this->owner, $original->id);
        $this->assertSame($original->currency, $reversal->currency);
        $this->assertTrue($reversal->isPosted());
        $this->assertSame('0.01', $reversal->lines->first()->credit);
    }

    public function test_failure_during_line_creation_rolls_back_every_new_record(): void
    {
        $original = $this->posted([$this->line('1', '0'), $this->line('0', '1')]);
        $before = $this->snapshot($original);
        $event = 'eloquent.created: '.JournalLine::class;
        Event::listen($event, function (JournalLine $line) use ($original) {
            if ((string) $line->journal_entry_id !== (string) $original->id) {
                throw new RuntimeException('Injected failure after first reversal line');
            }
        });
        try {
            $this->expectException(RuntimeException::class);
            (new ReverseJournalEntry)->execute($this->owner, $original->id);
        } finally {
            Event::forget($event);
            $this->assertSame($before, $this->snapshot($original));
            $this->assertDatabaseCount('journal_entries', 1);
            $this->assertDatabaseCount('journal_lines', 2);
        }
    }

    public function test_second_connection_cannot_create_a_concurrent_reversal(): void
    {
        $original = $this->posted([$this->line('1', '0'), $this->line('0', '1')]);
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.accounting_reversal_probe' => config('database.connections.mysql_testing')]);
        $probe = DB::connection('accounting_reversal_probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            DB::connection($originalConnection)->transaction(function () use ($original, $probe) {
                (new ReverseJournalEntry)->execute($this->owner, $original->id);
                DB::setDefaultConnection('accounting_reversal_probe');
                try {
                    (new ReverseJournalEntry)->execute($this->owner, $original->id);
                    $this->fail('Concurrent attempt must wait for the original row lock.');
                } catch (QueryException $exception) {
                    $this->assertContains($exception->errorInfo[1], [1205, 3572]);
                } finally {
                    DB::setDefaultConnection('mysql_testing');
                }
            });
            $this->expectConflict(fn () => (new ReverseJournalEntry)->execute($this->owner, $original->id));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::disconnect('accounting_reversal_probe');
        }
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertDatabaseCount('journal_lines', 4);
        $this->assertSame(1, JournalEntry::where('reversal_of_id', $original->id)->count());
    }

    public function test_policy_allows_only_owner_of_unreversed_posted_original(): void
    {
        $draft = $this->draft([$this->line('1', '0'), $this->line('0', '1')]);
        $original = $this->posted([$this->line('1', '0'), $this->line('0', '1')]);
        $other = User::factory()->create();

        $this->assertFalse(Gate::forUser($this->owner)->allows('reverse', $draft));
        $this->assertFalse(Gate::forUser($other)->allows('reverse', $original));
        $this->assertFalse(Gate::forUser(null)->allows('reverse', $original));
        $this->assertTrue(Gate::forUser($this->owner)->allows('reverse', $original));

        $reversal = (new ReverseJournalEntry)->execute($this->owner, $original->id);
        $this->assertFalse(Gate::forUser($this->owner)->allows('reverse', $original));
        $this->assertFalse(Gate::forUser($this->owner)->allows('reverse', $reversal));
    }

    private function expectConflict(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected reversal eligibility conflict.');
        } catch (AccountingConflict) {
            $this->assertTrue(true);
        }
    }
}
