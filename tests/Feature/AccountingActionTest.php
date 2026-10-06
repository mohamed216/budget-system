<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\DeleteChartAccount;
use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Actions\UpdateChartAccount;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    private function account(string $code = '1000', ?int $parent = null, ?User $actor = null): ChartAccount
    {
        return (new CreateChartAccount)->execute($actor ?? $this->owner, $code, ' Cash ', 'asset', true, $parent);
    }

    private function line(ChartAccount $account, string $debit = '1.2', string $credit = '0'): array
    {
        return ['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'description' => 'Line'];
    }

    private function draft(array $lines = []): JournalEntry
    {
        return (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), $lines);
    }

    private function update(ChartAccount $account, array $changes = []): ChartAccount
    {
        $data = array_replace(['code' => $account->code, 'name' => $account->name, 'type' => $account->type,
            'active' => $account->is_active, 'parent' => $account->parent_id], $changes);

        return (new UpdateChartAccount)->execute($this->owner, $account->id, $data['code'], $data['name'], $data['type'], $data['active'], $data['parent']);
    }

    private function rejects(callable $operation, string $exception): void
    {
        try {
            $operation();
            $this->fail('Expected '.$exception);
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }
    }

    public function test_chart_creation_normalizes_and_validates_fields_and_parent_ownership(): void
    {
        $account = $this->account(' cash.01_- ');
        $this->assertSame('CASH.01_-', $account->code);
        $this->assertSame('Cash', $account->name);
        $this->assertSame($this->owner->id, $account->user_id);
        $foreign = $this->account('1000', actor: $this->other);
        $this->rejects(fn () => $this->account('2000', $foreign->id), ModelNotFoundException::class);
        foreach (['bad code', 'é', '', str_repeat('A', 33)] as $code) {
            $this->rejects(fn () => $this->account($code), ValidationException::class);
        }
        $this->rejects(fn () => (new CreateChartAccount)->execute($this->owner, '2000', ' ', 'asset'), ValidationException::class);
        $this->rejects(fn () => (new CreateChartAccount)->execute($this->owner, '2000', 'Cash', 'ASSET'), ValidationException::class);
        $this->assertSame(1, $this->owner->chartAccounts()->count());
    }

    public function test_hierarchy_rejects_self_and_descendant_cycles_and_allows_reparenting(): void
    {
        $root = $this->account();
        $child = $this->account('1001', $root->id);
        $grandchild = $this->account('1002', $child->id);
        $this->rejects(fn () => $this->update($root, ['parent' => $root->id]), AccountingConflict::class);
        $this->rejects(fn () => $this->update($root, ['parent' => $grandchild->id]), AccountingConflict::class);
        $this->assertNull($root->fresh()->parent_id);
        $this->assertNull($this->update($child, ['parent' => null])->parent_id);
        $this->assertSame($child->id, $this->update($root, ['parent' => $child->id])->parent_id);
        $foreign = $this->account(actor: $this->other);
        $this->rejects(fn () => $this->update($root, ['parent' => $foreign->id]), ModelNotFoundException::class);
    }

    public function test_referenced_accounts_protect_structure_but_allow_rename_and_deactivation(): void
    {
        $account = $this->account();
        $this->draft([$this->line($account)]);
        $this->rejects(fn () => $this->update($account, ['code' => '2000', 'name' => 'Changed']), AccountingConflict::class);
        $this->rejects(fn () => $this->update($account, ['type' => 'expense']), AccountingConflict::class);
        $this->assertSame('Cash', $account->fresh()->name);
        $changed = $this->update($account, ['name' => ' Renamed ', 'active' => false]);
        $this->assertSame('Renamed', $changed->name);
        $this->assertFalse($changed->is_active);
        $this->rejects(fn () => (new DeleteChartAccount)->execute($this->owner, $account->id), AccountingConflict::class);
        $this->assertDatabaseCount('journal_lines', 1);
    }

    public function test_unused_leaf_can_change_structure_and_delete_but_parent_cannot_delete(): void
    {
        $parent = $this->account();
        $child = $this->account('1001', $parent->id);
        $this->rejects(fn () => (new DeleteChartAccount)->execute($this->owner, $parent->id), AccountingConflict::class);
        $child = $this->update($child, ['code' => ' exp ', 'type' => 'expense']);
        $this->assertSame('EXP', $child->code);
        $this->assertSame('expense', $child->type);
        (new DeleteChartAccount)->execute($this->owner, $child->id);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $child->id]);
    }

    public function test_foreign_chart_update_and_delete_are_rejected(): void
    {
        $foreign = $this->account(actor: $this->other);
        $this->rejects(fn () => $this->update($foreign), ModelNotFoundException::class);
        $this->rejects(fn () => (new DeleteChartAccount)->execute($this->owner, $foreign->id), ModelNotFoundException::class);
        $this->assertNotNull($foreign->fresh());
    }

    public function test_chart_save_failure_rolls_back_original_state(): void
    {
        $account = $this->account();
        $event = 'eloquent.updated: '.ChartAccount::class;
        Event::listen($event, fn () => throw new RuntimeException('Injected failure'));
        try {
            $this->rejects(fn () => $this->update($account, ['name' => 'Changed']), RuntimeException::class);
        } finally {
            Event::forget($event);
        }
        $this->assertSame('Cash', $account->fresh()->name);
    }

    public function test_empty_unbalanced_and_multiline_drafts_are_valid_with_server_fields(): void
    {
        $empty = $this->draft();
        $this->assertCount(0, $empty->lines);
        $this->assertSame(1, $empty->version);
        $this->assertTrue($empty->isDraft());
        $this->assertNull($empty->posted_at);
        $account = $this->account();
        $unbalanced = $this->draft([$this->line($account)]);
        $this->assertCount(1, $unbalanced->lines);
        $journal = $this->draft([9 => $this->line($account), 42 => $this->line($account, '0', '0.10')]);
        $this->assertSame([1, 2], $journal->lines->pluck('line_number')->all());
        $this->assertSame(['1.20', '0.00'], $journal->lines->pluck('debit')->all());
        $this->assertSame(['0.00', '0.10'], $journal->lines->pluck('credit')->all());
        foreach ($journal->lines as $line) {
            $this->assertSame($this->owner->id, $line->user_id);
            $this->assertSame($journal->id, $line->journal_entry_id);
        }
    }

    public function test_foreign_missing_and_inactive_accounts_reject_and_rollback_created_header(): void
    {
        $foreign = $this->account(actor: $this->other);
        $inactive = $this->update($this->account(), ['active' => false]);
        foreach ([$foreign, $inactive] as $account) {
            $this->rejects(fn () => $this->draft([$this->line($account)]), ValidationException::class);
        }
        $this->rejects(fn () => $this->draft([['chart_account_id' => 999999999, 'debit' => '1', 'credit' => '0']]), ValidationException::class);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_invalid_sides_and_money_are_rejected(): void
    {
        $account = $this->account();
        foreach ([['0', '0'], ['1', '1']] as [$debit, $credit]) {
            $this->rejects(fn () => $this->draft([$this->line($account, $debit, $credit)]), ValidationException::class);
        }
        foreach (['-1', '1e2', '1.234', '1,00', 'bad', '10000000000000.00'] as $amount) {
            $this->rejects(fn () => $this->draft([$this->line($account, $amount)]), InvalidArgumentException::class);
        }
        $this->rejects(fn () => $this->draft([['chart_account_id' => $account->id, 'debit' => 1.2, 'credit' => '0']]), ValidationException::class);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_currency_is_case_sensitive_and_configurable(): void
    {
        foreach (['sar', 'USD'] as $currency) {
            $this->rejects(fn () => (new SaveJournalDraft)->execute($this->owner, '2026-10-06', $currency, []), ValidationException::class);
        }
        config(['accounting.currency' => 'USD']);
        $this->assertSame('USD', $this->draft()->currency);
    }

    public function test_update_requires_version_and_replaces_all_lines_once(): void
    {
        $account = $this->account();
        $journal = $this->draft([$this->line($account)]);
        $oldLineId = $journal->lines->first()->id;
        $save = new SaveJournalDraft;
        foreach ([null, 0, 2] as $version) {
            $this->rejects(fn () => $save->execute($this->owner, '2026-10-07', config('accounting.currency'), [], journalId: $journal->id, version: $version), AccountingConflict::class);
        }
        $this->assertSame('2026-10-06', $journal->fresh()->entry_date->format('Y-m-d'));
        $this->assertDatabaseHas('journal_lines', ['id' => $oldLineId]);
        $updated = $save->execute($this->owner, '2026-10-07', config('accounting.currency'), [$this->line($account, '0', '2')], reference: 'R1', description: 'New', journalId: $journal->id, version: 1);
        $this->assertSame(2, $updated->version);
        $this->assertSame('R1', $updated->reference);
        $this->assertSame('New', $updated->description);
        $this->assertSame($this->owner->id, $updated->user_id);
        $this->assertNull($updated->posted_at);
        $this->assertDatabaseMissing('journal_lines', ['id' => $oldLineId]);
        $this->assertCount(1, $updated->lines);
        $this->assertSame('2.00', $updated->lines->first()->credit);
        $this->rejects(fn () => $save->execute($this->owner, '2026-10-08', config('accounting.currency'), [], journalId: $journal->id, version: 1), AccountingConflict::class);
        $this->assertSame(2, $journal->fresh()->version);
    }

    public function test_posted_and_foreign_journal_mutation_is_rejected(): void
    {
        $posted = $this->draft();
        $posted->forceFill(['status' => 'posted', 'posted_at' => now()])->save();
        $foreign = $this->other->journalEntries()->create(['entry_date' => '2026-10-06', 'currency' => config('accounting.currency')]);
        foreach ([[$posted, AccountingConflict::class], [$foreign, ModelNotFoundException::class]] as [$journal, $exception]) {
            $this->rejects(fn () => (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), [], journalId: $journal->id, version: 1), $exception);
            $this->rejects(fn () => (new DeleteJournalDraft)->execute($this->owner, $journal->id), $exception);
            $this->assertNotNull($journal->fresh());
        }
    }

    public function test_delete_draft_removes_lines_before_header_and_rolls_back_failure(): void
    {
        $journal = $this->draft([$this->line($this->account())]);
        $event = 'eloquent.deleting: '.JournalEntry::class;
        Event::listen($event, fn () => throw new RuntimeException('Injected deletion failure'));
        try {
            $this->rejects(fn () => (new DeleteJournalDraft)->execute($this->owner, $journal->id), RuntimeException::class);
        } finally {
            Event::forget($event);
        }
        $this->assertCount(1, $journal->fresh()->lines);
        (new DeleteJournalDraft)->execute($this->owner, $journal->id);
        $this->assertDatabaseMissing('journal_entries', ['id' => $journal->id]);
        $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $journal->id]);
    }

    public function test_failed_line_creation_rolls_back_header_lines_and_update_version(): void
    {
        $account = $this->account();
        $journal = $this->draft([$this->line($account)]);
        $oldId = $journal->lines->first()->id;
        $event = 'eloquent.created: '.JournalLine::class;
        $count = 0;
        Event::listen($event, function () use (&$count) {
            if (++$count === 2) {
                throw new RuntimeException('Injected second line failure');
            }
        });
        try {
            $lines = [$this->line($account), $this->line($account)];
            $this->rejects(fn () => $this->draft($lines), RuntimeException::class);
            $count = 0;
            $this->rejects(fn () => (new SaveJournalDraft)->execute($this->owner, '2026-10-07', config('accounting.currency'), $lines, journalId: $journal->id, version: 1), RuntimeException::class);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 1);
        $this->assertSame(1, $journal->fresh()->version);
        $this->assertSame('2026-10-06', $journal->fresh()->entry_date->format('Y-m-d'));
        $this->assertDatabaseHas('journal_lines', ['id' => $oldId]);
    }

    public function test_protected_line_input_and_create_version_override_are_rejected(): void
    {
        $line = $this->line($this->account());
        foreach (['user_id', 'status', 'posted_at', 'version', 'line_number', 'journal_entry_id', 'id'] as $key) {
            $this->rejects(fn () => $this->draft(array_replace([$line], [array_merge($line, [$key => 999])])), ValidationException::class);
        }
        $this->rejects(fn () => (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), [], version: 99), AccountingConflict::class);
        $this->assertDatabaseCount('journal_entries', 0);
        // Header and chart fields are explicit method parameters, never an arbitrary input array.
        $this->assertSame(['actor', 'code', 'name', 'type', 'isActive', 'parentId'], array_map(fn ($p) => $p->getName(), (new \ReflectionMethod(CreateChartAccount::class, 'execute'))->getParameters()));
    }

    public function test_locking_sql_orders_header_lines_then_distinct_chart_accounts(): void
    {
        $first = $this->account();
        $second = $this->account('1001');
        $journal = $this->draft([$this->line($first)]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            (new SaveJournalDraft)->execute($this->owner, '2026-10-06', config('accounting.currency'), [$this->line($second), $this->line($first), $this->line($second)], journalId: $journal->id, version: 1);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')));
            $this->assertCount(3, $locks);
            $this->assertStringContainsString('journal_entries', $locks[0]['query']);
            $this->assertStringContainsString('journal_lines', $locks[1]['query']);
            $this->assertStringContainsString('chart_of_accounts', $locks[2]['query']);
            $this->assertStringContainsString('order by `id` asc', $locks[2]['query']);
            $this->assertSame([$this->owner->id, $first->id, $second->id], $locks[2]['bindings']);
            DB::flushQueryLog();
            $this->update($first, ['name' => 'Renamed']);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')));
            $this->assertCount(2, $locks);
            $this->assertStringContainsString('users', $locks[0]['query']);
            $this->assertStringContainsString('order by `id` asc', $locks[1]['query']);
            $this->assertStringContainsString('chart_of_accounts', $locks[1]['query']);
            $lineReads = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'journal_lines')));
            $this->assertCount(1, $lineReads);
            $this->assertStringNotContainsString('for update', $lineReads[0]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_chart_deletion_checks_references_without_locking_journal_lines(): void
    {
        $referenced = $this->account();
        $unused = $this->account('1001');
        $this->draft([$this->line($referenced)]);
        DB::enableQueryLog();
        try {
            foreach ([$referenced, $unused] as $account) {
                DB::flushQueryLog();
                if ($account->is($referenced)) {
                    $this->rejects(fn () => (new DeleteChartAccount)->execute($this->owner, $account->id), AccountingConflict::class);
                } else {
                    (new DeleteChartAccount)->execute($this->owner, $account->id);
                }
                $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')));
                $this->assertCount(2, $locks);
                $this->assertStringContainsString('users', $locks[0]['query']);
                $this->assertStringContainsString('chart_of_accounts', $locks[1]['query']);
                $this->assertStringContainsString('order by `id` asc', $locks[1]['query']);
                $lineReads = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'journal_lines')));
                $this->assertCount(1, $lineReads);
                $this->assertStringNotContainsString('for update', $lineReads[0]['query']);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertNotNull($referenced->fresh());
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $unused->id]);
        $this->assertDatabaseCount('journal_lines', 1);
    }
}
