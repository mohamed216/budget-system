<?php

namespace Tests\Feature;

use App\Accounting\Actions\CloseAccountingPeriod;
use App\Accounting\Actions\CreateAccountingPeriod;
use App\Accounting\Actions\DeleteAccountingPeriod;
use App\Accounting\Actions\ReopenAccountingPeriod;
use App\Accounting\Actions\UpdateAccountingPeriod;
use App\Accounting\Exceptions\AccountingConflict;
use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class AccountingPeriodActionTest extends TestCase
{
    use RefreshFinancialDatabase;

    private function create(User $actor, string $start = '2026-01-01', string $end = '2026-01-31'): AccountingPeriod
    {
        return (new CreateAccountingPeriod)->execute($actor, $start, $end);
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

    public function test_create_uses_actor_ownership_defaults_and_strict_date_validation(): void
    {
        $owner = User::factory()->create();
        $period = $this->create($owner);
        $this->assertSame($owner->id, $period->user_id);
        $this->assertSame('2026-01-01', $period->start_date->format('Y-m-d'));
        $this->assertSame('2026-01-31', $period->end_date->format('Y-m-d'));
        $this->assertTrue($period->isOpen());
        $this->assertNull($period->first_closed_at);

        $this->rejects(fn () => $this->create($owner, '2026-2-01', '2026-02-28'), ValidationException::class);
        $this->rejects(fn () => $this->create($owner, '2026-02-30', '2026-03-01'), ValidationException::class);
        $this->rejects(fn () => $this->create($owner, '2026-03-02', '2026-03-01'), ValidationException::class);
        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_create_rejects_inclusive_overlap_but_allows_adjacent_and_different_owners(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->create($owner, '2026-04-10', '2026-04-20');
        foreach ([['2026-04-01', '2026-04-10'], ['2026-04-20', '2026-04-30'], ['2026-04-12', '2026-04-18']] as [$start, $end]) {
            $this->rejects(fn () => $this->create($owner, $start, $end), AccountingConflict::class);
        }
        $this->create($owner, '2026-04-01', '2026-04-09');
        $this->create($owner, '2026-04-21', '2026-04-30');
        $this->create($other, '2026-04-10', '2026-04-20');
        $this->assertSame(3, AccountingPeriod::ownedBy($owner)->count());
        $this->assertSame(1, AccountingPeriod::ownedBy($other)->count());
    }

    public function test_update_changes_only_dates_and_rejects_overlap_and_invalid_dates(): void
    {
        $owner = User::factory()->create();
        $period = $this->create($owner, '2026-05-01', '2026-05-10');
        $this->create($owner, '2026-05-20', '2026-05-31');
        $updated = (new UpdateAccountingPeriod)->execute($owner, $period->id, '2026-05-02', '2026-05-19');
        $this->assertSame('2026-05-02', $updated->start_date->format('Y-m-d'));
        $this->assertSame('2026-05-19', $updated->end_date->format('Y-m-d'));
        $this->assertTrue($updated->isOpen());
        $this->assertNull($updated->first_closed_at);

        $this->rejects(fn () => (new UpdateAccountingPeriod)->execute($owner, $period->id, '2026-05-02', '2026-05-20'), AccountingConflict::class);
        $this->rejects(fn () => (new UpdateAccountingPeriod)->execute($owner, $period->id, '2026-05-21', '2026-05-20'), ValidationException::class);
        $this->assertSame('2026-05-19', $period->fresh()->end_date->format('Y-m-d'));
    }

    public function test_close_reopen_preserves_first_timestamp_and_disallows_later_update(): void
    {
        $owner = User::factory()->create();
        $period = $this->create($owner);
        $closed = (new CloseAccountingPeriod)->execute($owner, $period->id);
        $this->assertTrue($closed->isClosed());
        $this->assertTrue($closed->wasEverClosed());
        $firstClosed = $closed->first_closed_at->format('Y-m-d H:i:s.u');
        $this->rejects(fn () => (new CloseAccountingPeriod)->execute($owner, $period->id), AccountingConflict::class);
        $this->rejects(fn () => (new UpdateAccountingPeriod)->execute($owner, $period->id, '2026-01-02', '2026-01-31'), AccountingConflict::class);

        $opened = (new ReopenAccountingPeriod)->execute($owner, $period->id);
        $this->assertTrue($opened->isOpen());
        $this->assertSame($firstClosed, $opened->first_closed_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($firstClosed, $opened->fresh()->first_closed_at->format('Y-m-d H:i:s.u'));
        $this->rejects(fn () => (new ReopenAccountingPeriod)->execute($owner, $period->id), AccountingConflict::class);
        $this->rejects(fn () => (new UpdateAccountingPeriod)->execute($owner, $period->id, '2026-01-02', '2026-01-31'), AccountingConflict::class);
        $this->assertSame('2026-01-01', $period->fresh()->start_date->format('Y-m-d'));
    }

    public function test_delete_only_never_closed_open_period(): void
    {
        $owner = User::factory()->create();
        $new = $this->create($owner);
        (new DeleteAccountingPeriod)->execute($owner, $new->id);
        $this->assertDatabaseMissing('accounting_periods', ['id' => $new->id]);

        $closed = $this->create($owner, '2026-02-01', '2026-02-28');
        (new CloseAccountingPeriod)->execute($owner, $closed->id);
        $this->rejects(fn () => (new DeleteAccountingPeriod)->execute($owner, $closed->id), AccountingConflict::class);
        (new ReopenAccountingPeriod)->execute($owner, $closed->id);
        $this->rejects(fn () => (new DeleteAccountingPeriod)->execute($owner, $closed->id), AccountingConflict::class);
        $this->assertDatabaseHas('accounting_periods', ['id' => $closed->id]);
    }

    public function test_foreign_and_missing_period_ids_are_not_found_for_every_mutation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->create($other);
        foreach ([$foreign->id, $foreign->id + 100000] as $id) {
            $this->rejects(fn () => (new UpdateAccountingPeriod)->execute($owner, $id, 'bad', 'bad'), ModelNotFoundException::class);
            $this->rejects(fn () => (new CloseAccountingPeriod)->execute($owner, $id), ModelNotFoundException::class);
            $this->rejects(fn () => (new ReopenAccountingPeriod)->execute($owner, $id), ModelNotFoundException::class);
            $this->rejects(fn () => (new DeleteAccountingPeriod)->execute($owner, $id), ModelNotFoundException::class);
        }
        $this->assertTrue($foreign->fresh()->isOpen());
    }

    public function test_policy_follows_ownership_and_transition_eligibility(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $period = $this->create($owner);
        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', AccountingPeriod::class));
        $this->assertTrue(Gate::forUser($owner)->allows('create', AccountingPeriod::class));
        foreach (['view', 'update', 'close', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $period));
            $this->assertFalse(Gate::forUser($other)->allows($ability, $period));
        }
        $this->assertFalse(Gate::forUser($owner)->allows('reopen', $period));
        $period = (new CloseAccountingPeriod)->execute($owner, $period->id);
        $this->assertTrue(Gate::forUser($owner)->allows('view', $period));
        $this->assertTrue(Gate::forUser($owner)->allows('reopen', $period));
        foreach (['update', 'close', 'delete'] as $ability) {
            $this->assertFalse(Gate::forUser($owner)->allows($ability, $period));
        }
        $period = (new ReopenAccountingPeriod)->execute($owner, $period->id);
        $this->assertTrue(Gate::forUser($owner)->allows('close', $period));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $period));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $period));
        $this->assertFalse(Gate::forUser($other)->allows('reopen', $period));
    }

    public function test_owner_lock_precedes_period_lock_or_overlap_read(): void
    {
        $owner = User::factory()->create();
        $period = $this->create($owner);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            if (str_contains(strtolower($event->sql), 'for update')) {
                $queries[] = strtolower($event->sql);
            }
        });
        (new CloseAccountingPeriod)->execute($owner, $period->id);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('users', $queries[0]);
        $this->assertStringContainsString('accounting_periods', $queries[1]);
    }

    public function test_injected_failure_rolls_back_created_period(): void
    {
        $owner = User::factory()->create();
        $event = 'eloquent.created: '.AccountingPeriod::class;
        Event::listen($event, fn () => throw new RuntimeException('Injected period failure'));
        try {
            $this->rejects(fn () => $this->create($owner), RuntimeException::class);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('accounting_periods', 0);
    }

    public function test_second_connection_waits_for_owner_lock_then_rejects_committed_overlap(): void
    {
        $owner = User::factory()->create();
        $ownerId = $owner->id;
        $originalConnection = DB::getDefaultConnection();
        config(['database.connections.accounting_period_probe' => config('database.connections.mysql_testing')]);
        $probe = DB::connection('accounting_period_probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Publish the fixture so the second connection can see it; clean up explicitly below.
        DB::commit();
        try {
            DB::beginTransaction();
            $first = $this->create($owner, '2026-08-01', '2026-08-31');
            DB::setDefaultConnection('accounting_period_probe');
            try {
                $this->create($owner, '2026-08-15', '2026-09-15');
                $this->fail('Concurrent create should wait for the owner lock.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->errorInfo[1], [1205, 3572]);
            } finally {
                DB::setDefaultConnection($originalConnection);
            }
            DB::commit();

            $this->rejects(fn () => (new CreateAccountingPeriod)->execute($owner, '2026-08-15', '2026-09-15'), AccountingConflict::class);
            $this->assertSame(1, AccountingPeriod::ownedBy($owner)->count());
            $this->assertSame($first->id, AccountingPeriod::ownedBy($owner)->first()->id);
        } finally {
            DB::setDefaultConnection($originalConnection);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('accounting_periods')->where('user_id', $ownerId)->delete();
            DB::table('users')->where('id', $ownerId)->delete();
            DB::beginTransaction();
            DB::disconnect('accounting_period_probe');
        }
    }
}
