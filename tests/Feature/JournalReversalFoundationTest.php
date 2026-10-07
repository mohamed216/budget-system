<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class JournalReversalFoundationTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function journal(User $owner, ?int $reversalOfId = null): JournalEntry
    {
        $attributes = [
            'user_id' => $owner->id,
            'entry_date' => '2026-10-07',
            'currency' => config('accounting.currency'),
            'reversal_of_id' => $reversalOfId,
        ];

        return JournalEntry::findOrFail(DB::table('journal_entries')->insertGetId($attributes));
    }

    private function rejects(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject the invalid reversal link.');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    public function test_existing_journals_default_to_null_reversal_link_and_keep_draft_behavior(): void
    {
        $owner = User::factory()->create();
        $first = $owner->journalEntries()->create(['entry_date' => '2026-10-07', 'currency' => config('accounting.currency')]);
        $second = $owner->journalEntries()->create(['entry_date' => '2026-10-07', 'currency' => config('accounting.currency')]);

        $column = collect(Schema::getColumns('journal_entries'))->firstWhere('name', 'reversal_of_id');
        $this->assertSame('bigint unsigned', $column['type']);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertNull($first->fresh()->reversal_of_id);
        $this->assertNull($second->fresh()->reversal_of_id);
        $this->assertNull($first->reversalOf);
        $this->assertNull($first->reversal);
        $this->assertTrue($first->isDraft());
        $this->assertSame(1, $first->version);
    }

    public function test_same_owner_link_and_model_relationships_resolve(): void
    {
        $owner = User::factory()->create();
        $original = $this->journal($owner);
        $reversal = $this->journal($owner, $original->id);

        $this->assertTrue($reversal->reversalOf->is($original));
        $this->assertTrue($original->reversal->is($reversal));
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertNull($original->fresh()->reversal_of_id);
    }

    public function test_unique_link_rejects_a_second_reversal(): void
    {
        $owner = User::factory()->create();
        $original = $this->journal($owner);
        $this->journal($owner, $original->id);

        $this->rejects(fn () => $this->journal($owner, $original->id), 1062);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_composite_foreign_key_rejects_cross_owner_and_missing_original(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $original = $this->journal($owner);

        $this->rejects(fn () => $this->journal($other, $original->id), 1452);
        $this->rejects(fn () => $this->journal($owner, $original->id + 1000), 1452);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_referenced_original_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $original = $this->journal($owner);
        $this->journal($owner, $original->id);

        $this->rejects(fn () => DB::table('journal_entries')->where('id', $original->id)->delete(), 1451);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_rollback_guard_refuses_to_remove_nonempty_reversal_tracking(): void
    {
        $owner = User::factory()->create();
        $original = $this->journal($owner);
        $reversal = $this->journal($owner, $original->id);
        $migration = require database_path('migrations/2026_10_07_000001_add_journal_reversal_link.php');

        try {
            $migration->down();
            $this->fail('Expected the rollback guard to reject existing reversal links.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to remove journal reversal tracking', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('journal_entries', 'reversal_of_id'));
        $this->assertSame($original->id, $reversal->fresh()->reversal_of_id);
    }

    public function test_reversal_link_cannot_be_mass_assigned(): void
    {
        $owner = User::factory()->create();
        $original = $this->journal($owner);
        $entry = $owner->journalEntries()->create([
            'entry_date' => '2026-10-07',
            'currency' => config('accounting.currency'),
            'reversal_of_id' => $original->id,
        ]);

        $this->assertFalse($entry->isFillable('reversal_of_id'));
        $this->assertNull($entry->fresh()->reversal_of_id);
    }
}
