<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class PostedJournalImmutabilityTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private JournalEntry $posted;

    private JournalEntry $draft;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $account = (new CreateChartAccount)->execute($this->owner, '1000', 'Cash', 'asset');
        $this->accountId = $account->id;
        $save = new SaveJournalDraft;
        $lines = [
            ['chart_account_id' => $account->id, 'debit' => '1', 'credit' => '0'],
            ['chart_account_id' => $account->id, 'debit' => '0', 'credit' => '1'],
        ];
        $this->posted = $save->execute($this->owner, '2026-10-06', config('accounting.currency'), $lines);
        $this->posted = (new PostJournalEntry)->execute($this->owner, $this->posted->id);
        $this->draft = $save->execute($this->owner, '2026-10-06', config('accounting.currency'), []);
    }

    private function rejects(callable $mutation): void
    {
        $header = DB::table('journal_entries')->orderBy('id')->get()->toJson();
        $lines = DB::table('journal_lines')->orderBy('id')->get()->toJson();
        try {
            $mutation();
            $this->fail('Expected the database trigger to reject the mutation.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertSame(1644, $exception->errorInfo[1]);
        }
        $this->assertSame($header, DB::table('journal_entries')->orderBy('id')->get()->toJson());
        $this->assertSame($lines, DB::table('journal_lines')->orderBy('id')->get()->toJson());
    }

    public function test_all_posted_header_updates_including_noop_and_deletion_are_rejected(): void
    {
        $foreign = User::factory()->create();
        foreach ([['entry_date' => '2026-10-07'], ['currency' => 'USD'], ['reference' => 'Changed'],
            ['description' => 'Changed'], ['user_id' => $foreign->id], ['status' => 'draft', 'posted_at' => null],
            ['posted_at' => '2026-10-07 12:00:00'], ['version' => 99], ['created_at' => now()], ['updated_at' => now()]] as $fields) {
            $this->rejects(fn () => DB::table('journal_entries')->where('id', $this->posted->id)->update($fields));
        }
        $this->rejects(fn () => DB::statement('UPDATE journal_entries SET reference = reference WHERE id = ?', [$this->posted->id]));
        $this->rejects(fn () => DB::table('journal_entries')->where('id', $this->posted->id)->delete());
    }

    public function test_posted_line_insertion_update_and_deletion_are_rejected(): void
    {
        $second = (new CreateChartAccount)->execute($this->owner, '1001', 'Other', 'asset');
        $lineId = $this->posted->lines->first()->id;
        $foreign = User::factory()->create();
        foreach ([['chart_account_id' => $second->id], ['debit' => '2.00'], ['debit' => '0.00', 'credit' => '1.00'],
            ['description' => 'Changed'], ['line_number' => 3], ['user_id' => $foreign->id],
            ['created_at' => now()], ['updated_at' => now()]] as $fields) {
            $this->rejects(fn () => DB::table('journal_lines')->where('id', $lineId)->update($fields));
        }
        $this->rejects(fn () => DB::table('journal_lines')->insert([
            'user_id' => $this->owner->id, 'journal_entry_id' => $this->posted->id,
            'chart_account_id' => $this->accountId, 'line_number' => 3, 'debit' => '1.00', 'credit' => '0.00',
        ]));
        $this->rejects(fn () => DB::statement('DELETE FROM journal_lines WHERE id = ?', [$lineId]));
    }

    public function test_lines_cannot_move_between_posted_and_draft_in_either_direction(): void
    {
        $draftLine = DB::table('journal_lines')->insertGetId([
            'user_id' => $this->owner->id, 'journal_entry_id' => $this->draft->id,
            'chart_account_id' => $this->accountId, 'line_number' => 3, 'debit' => '1.00', 'credit' => '0.00',
        ]);
        $this->rejects(fn () => DB::table('journal_lines')->where('id', $draftLine)->update(['journal_entry_id' => $this->posted->id]));
        $this->rejects(fn () => DB::table('journal_lines')->where('id', $this->posted->lines->first()->id)->update(['journal_entry_id' => $this->draft->id]));
    }

    public function test_raw_draft_header_and_line_mutations_remain_allowed(): void
    {
        $this->assertSame(1, DB::table('journal_entries')->where('id', $this->draft->id)->update(['reference' => 'Draft update']));
        $lineId = DB::table('journal_lines')->insertGetId([
            'user_id' => $this->owner->id, 'journal_entry_id' => $this->draft->id,
            'chart_account_id' => $this->accountId, 'line_number' => 1, 'debit' => '1.00', 'credit' => '0.00',
        ]);
        $this->assertSame(1, DB::table('journal_lines')->where('id', $lineId)->update(['description' => 'Allowed', 'debit' => '2.00']));
        $this->assertSame(1, DB::table('journal_lines')->where('id', $lineId)->delete());
        $this->assertSame(1, DB::table('journal_entries')->where('id', $this->draft->id)->delete());
    }

    public function test_raw_posted_header_insert_is_rejected_but_draft_to_posted_is_allowed(): void
    {
        $this->rejects(fn () => DB::table('journal_entries')->insert([
            'user_id' => $this->owner->id, 'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
            'status' => 'posted', 'posted_at' => now(),
        ]));
        // Posting balance validation remains in the action, outside the trigger scope.
        $this->assertSame(1, DB::table('journal_entries')->where('id', $this->draft->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'version' => 2,
        ]));
        $this->assertTrue($this->draft->fresh()->isPosted());
    }
}
