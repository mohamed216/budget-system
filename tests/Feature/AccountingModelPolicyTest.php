<?php

namespace Tests\Feature;

use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Policies\ChartAccountPolicy;
use App\Policies\JournalEntryPolicy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingModelPolicyTest extends TestCase
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

    private function account(User $user, array $values = []): ChartAccount
    {
        return $user->chartAccounts()->create(array_replace([
            'code' => '1000', 'name' => 'Cash', 'type' => 'asset',
        ], $values));
    }

    private function journal(User $user): JournalEntry
    {
        return $user->journalEntries()->create([
            'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
        ]);
    }

    private function line(JournalEntry $journal, ChartAccount $account, int $number, string $debit, string $credit): JournalLine
    {
        // Protected relationship/ownership fields are assigned explicitly by server code.
        $line = new JournalLine(['chart_account_id' => $account->id, 'debit' => $debit, 'credit' => $credit]);
        $line->user_id = $journal->user_id;
        $line->line_number = $number;
        $journal->lines()->save($line);

        return $line;
    }

    public function test_ownership_scopes_and_user_relationships_exclude_foreign_records(): void
    {
        $account = $this->account($this->owner);
        $this->account($this->other);
        $journal = $this->journal($this->owner);
        $this->journal($this->other);
        $this->assertSame([$account->id], ChartAccount::ownedBy($this->owner)->pluck('id')->all());
        $this->assertSame([$journal->id], JournalEntry::ownedBy($this->owner)->pluck('id')->all());
        $this->assertSame([$account->id], $this->owner->chartAccounts()->pluck('id')->all());
        $this->assertSame([$journal->id], $this->owner->journalEntries()->pluck('id')->all());
        $this->assertTrue($account->user->is($this->owner));
        $this->assertTrue($journal->user->is($this->owner));
    }

    public function test_chart_hierarchy_relationships_and_boolean_cast(): void
    {
        $parent = $this->account($this->owner);
        $child = $this->account($this->owner, ['code' => '1001', 'parent_id' => $parent->id, 'is_active' => false]);
        $this->assertNull($parent->parent);
        $this->assertTrue($child->parent->is($parent));
        $this->assertSame([$child->id], $parent->children->modelKeys());
        $this->assertTrue($parent->fresh()->is_active);
        $this->assertFalse($child->fresh()->is_active);
        $this->assertArrayNotHasKey('balance', $parent->getAttributes());
        $this->assertArrayNotHasKey('balance', $parent->getCasts());
    }

    public function test_journal_lines_are_ordered_and_relationships_resolve(): void
    {
        $account = $this->account($this->owner);
        $journal = $this->journal($this->owner);
        $second = $this->line($journal, $account, 2, '0.00', '1.23');
        $first = $this->line($journal, $account, 1, '1.23', '0.00');
        $this->assertSame([$first->id, $second->id], $journal->fresh()->lines->modelKeys());
        $this->assertSame([$first->id, $second->id], $journal->lines()->pluck('id')->all());
        $this->assertTrue($first->journalEntry->is($journal));
        $this->assertTrue($first->chartAccount->is($account));
        $this->assertTrue($first->user->is($this->owner));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $account->journalLines->modelKeys());
        $foreignJournal = $this->journal($this->other);
        $this->line($foreignJournal, $this->account($this->other), 1, '2.00', '0.00');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], JournalLine::ownedBy($this->owner)->pluck('id')->all());
    }

    public function test_decimal_casts_preserve_exact_strings(): void
    {
        $account = $this->account($this->owner);
        $journal = $this->journal($this->owner);
        $debit = $this->line($journal, $account, 1, '9999999999999.99', '0')->fresh();
        $credit = $this->line($journal, $account, 2, '0', '0.10')->fresh();
        $this->assertSame('9999999999999.99', $debit->debit);
        $this->assertSame('0.00', $debit->credit);
        $this->assertSame('0.00', $credit->debit);
        $this->assertSame('0.10', $credit->credit);
        $this->assertSame('0.10', $credit->toArray()['credit']);
    }

    public function test_journal_dates_version_and_status_helpers(): void
    {
        $journal = $this->journal($this->owner)->fresh();
        $this->assertInstanceOf(Carbon::class, $journal->entry_date);
        $this->assertSame('2026-10-06', $journal->entry_date->format('Y-m-d'));
        $this->assertNull($journal->posted_at);
        $this->assertSame(1, $journal->version);
        $this->assertTrue($journal->isDraft());
        $this->assertFalse($journal->isPosted());
        // Schema fixture only; this step deliberately supplies no posting operation.
        $journal->forceFill(['status' => 'posted', 'posted_at' => '2026-10-06 12:00:00.123456', 'version' => 2])->save();
        $journal = $journal->fresh();
        $this->assertFalse($journal->isDraft());
        $this->assertTrue($journal->isPosted());
        $this->assertSame(2, $journal->version);
        $this->assertInstanceOf(CarbonImmutable::class, $journal->posted_at);
        $this->assertSame('2026-10-06 12:00:00.123456', $journal->posted_at->format('Y-m-d H:i:s.u'));
        $this->assertNotSame($journal->posted_at, $journal->posted_at->addDay());
        $this->assertSame('2026-10-06', $journal->posted_at->format('Y-m-d'));
        $this->assertTrue((new JournalEntry)->isDraft());
        $this->assertFalse((new JournalEntry)->isPosted());
    }

    public function test_protected_fields_are_not_mass_assignable(): void
    {
        $cases = [
            [new ChartAccount, ['user_id', 'id']],
            [new JournalEntry, ['user_id', 'status', 'posted_at', 'version', 'id', 'reversal_of_id']],
            [new JournalLine, ['user_id', 'journal_entry_id', 'line_number', 'id']],
        ];
        foreach ($cases as [$model, $fields]) {
            foreach ($fields as $field) {
                $this->assertFalse($model->isFillable($field), get_class($model).'.'.$field);
            }
        }
        $entry = new JournalEntry(['entry_date' => '2026-10-06', 'status' => 'posted', 'version' => 99, 'posted_at' => '2026-10-06 12:00:00', 'user_id' => $this->other->id, 'id' => 99]);
        $this->assertTrue($entry->isDraft());
        $this->assertSame(1, $entry->version);
        $this->assertNull($entry->posted_at);
        $this->assertNull($entry->user_id);
        $this->assertNull($entry->id);
    }

    public function test_policies_are_auto_discovered(): void
    {
        $this->assertInstanceOf(ChartAccountPolicy::class, Gate::getPolicyFor(ChartAccount::class));
        $this->assertInstanceOf(JournalEntryPolicy::class, Gate::getPolicyFor(JournalEntry::class));
    }

    public function test_authenticated_create_and_view_any_and_guest_denial(): void
    {
        foreach ([ChartAccount::class, JournalEntry::class] as $class) {
            foreach (['create', 'viewAny'] as $ability) {
                $this->assertTrue(Gate::forUser($this->owner)->allows($ability, $class));
                $this->assertTrue(Gate::forUser($this->other)->allows($ability, $class));
                $this->assertFalse(Gate::forUser(null)->allows($ability, $class));
            }
        }
    }

    public function test_chart_policy_permits_owner_and_rejects_foreign_user(): void
    {
        $account = $this->account($this->owner);
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($this->owner)->allows($ability, $account));
            $this->assertFalse(Gate::forUser($this->other)->allows($ability, $account));
            $this->assertFalse(Gate::forUser(null)->allows($ability, $account));
        }
        foreach (['restore', 'forceDelete'] as $ability) {
            $this->assertFalse(Gate::forUser($this->owner)->allows($ability, $account));
            $this->assertFalse(Gate::forUser($this->other)->allows($ability, $account));
        }
    }

    public function test_draft_journal_policy_permits_owner_only(): void
    {
        $journal = $this->journal($this->owner);
        foreach (['view', 'update', 'delete', 'post'] as $ability) {
            $this->assertTrue(Gate::forUser($this->owner)->allows($ability, $journal));
            $this->assertFalse(Gate::forUser($this->other)->allows($ability, $journal));
            $this->assertFalse(Gate::forUser(null)->allows($ability, $journal));
        }
        foreach (['restore', 'forceDelete'] as $ability) {
            $this->assertFalse(Gate::forUser($this->owner)->allows($ability, $journal));
            $this->assertFalse(Gate::forUser($this->other)->allows($ability, $journal));
        }
    }

    public function test_posted_journal_is_viewable_but_not_authorized_for_mutation(): void
    {
        $journal = $this->journal($this->owner);
        $journal->forceFill(['status' => 'posted', 'posted_at' => '2026-10-06 12:00:00'])->save();
        $this->assertTrue(Gate::forUser($this->owner)->allows('view', $journal));
        $this->assertFalse(Gate::forUser($this->other)->allows('view', $journal));
        foreach (['update', 'delete', 'post', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse(Gate::forUser($this->owner)->allows($ability, $journal));
            $this->assertFalse(Gate::forUser($this->other)->allows($ability, $journal));
        }
    }
}
