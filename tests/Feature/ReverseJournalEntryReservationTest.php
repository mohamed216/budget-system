<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\ReverseJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Http\Presenters\AccountingConflictPresentation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class ReverseJournalEntryReservationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function linkableFixture(): array
    {
        $f = $this->boundFixture();
        $journal = (new SaveJournalDraft)->execute($f['owner'], '2026-10-06', config('accounting.currency'), [
            ['chart_account_id' => $f['charts']['asset'], 'debit' => '100.00', 'credit' => '0.00'],
            ['chart_account_id' => $f['charts']['revenue'], 'debit' => '0.00', 'credit' => '100.00'],
        ], 'Operational transaction', 'Same shape as an integrated journal');
        $f['history_journal'] = (new PostJournalEntry)->execute($f['owner'], $journal->id)->id;
        $f['transaction'] = DB::table('transactions')->insertGetId(['user_id' => $f['owner']->id,
            'account_id' => $f['account'], 'category_id' => $f['category'], 'type' => 'income',
            'amount' => '100.00', 'date' => '2026-10-06']);

        return $f;
    }

    /** Exact, valid isolated provenance fixture; not an integrated posting implementation. */
    private function insertLink(array $f, ?string $connection = null): void
    {
        DB::connection($connection)->table('operational_transaction_journals')->insert([
            'user_id' => $f['owner']->id, 'transaction_id' => $f['transaction'],
            'journal_entry_id' => $f['history_journal'], 'account_id' => $f['account'], 'category_id' => $f['category'],
        ]);
    }

    private function rejectsUnchanged(array $f, int $journal, AccountingConflictReason $reason, string $message): void
    {
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new ReverseJournalEntry)->execute($f['owner'], $journal);
            $this->fail('Protected journal reversal accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($message, $exception->getMessage());
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->assertDatabaseMissing('journal_entries', ['reversal_of_id' => $journal]);
    }

    public static function states(): array
    {
        return [['legacy'], ['reconciling'], ['ready'], ['active']];
    }

    #[DataProvider('states')]
    public function test_explicit_operational_link_rejects_in_every_integration_state(string $state): void
    {
        $f = $this->boundFixture('historical_backfill');
        $changes = ['state' => $state];
        if ($state === 'legacy') {
            $changes += ['reconciliation_reference' => null, 'reconciled_at' => null];
        } elseif ($state === 'active') {
            $changes['activated_at'] = now()->format('Y-m-d H:i:s.u');
        }
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update($changes);
        $this->rejectsUnchanged($f, $f['journal'], AccountingConflictReason::OperationalJournalLinked,
            'Integrated operational journals cannot be reversed through the generic reversal action.');
    }

    public function test_same_shape_and_operational_text_without_a_link_remain_reversible(): void
    {
        $f = $this->linkableFixture();
        $this->assertDatabaseMissing('operational_transaction_journals', ['journal_entry_id' => $f['history_journal']]);
        $before = (array) DB::table('journal_entries')->where('id', $f['history_journal'])->first();
        $reversal = (new ReverseJournalEntry)->execute($f['owner'], $f['history_journal']);
        $this->assertTrue($reversal->isPosted());
        $this->assertSame($f['history_journal'], $reversal->reversal_of_id);
        $this->assertCount(2, $reversal->lines);
        $this->assertSame($before, (array) DB::table('journal_entries')->where('id', $f['history_journal'])->first());
    }

    public function test_foreign_owner_link_does_not_block_another_owners_manual_journal(): void
    {
        $linked = $this->boundFixture('historical_backfill');
        $manual = $this->linkableFixture();
        $before = $this->allFixtureRows($linked['owner']->id);
        $this->assertTrue((new ReverseJournalEntry)->execute($manual['owner'], $manual['history_journal'])->isPosted());
        $this->assertSame($before, $this->allFixtureRows($linked['owner']->id));
    }

    public function test_duplicate_precedes_a_later_operational_link_and_remains_a_rejection(): void
    {
        $f = $this->linkableFixture();
        $reversal = (new ReverseJournalEntry)->execute($f['owner'], $f['history_journal']);
        $this->insertLink($f);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new ReverseJournalEntry)->execute($f['owner'], $f['history_journal']);
            $this->fail('Duplicate reversal returned or created another journal.');
        } catch (AccountingConflict $exception) {
            $this->assertSame('Journal has already been reversed.', $exception->getMessage());
            $this->assertNull($exception->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->assertSame($reversal->id, DB::table('journal_entries')->where('reversal_of_id', $f['history_journal'])->value('id'));
    }

    public static function activity(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('activity')]
    public function test_unlinked_active_reserved_asset_rejects_even_when_disabled(bool $activeChart): void
    {
        $f = $this->boundFixture();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['state' => 'active', 'activated_at' => now()->format('Y-m-d H:i:s.u')]);
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['is_active' => $activeChart]);
        $this->rejectsUnchanged($f, $f['journal'], AccountingConflictReason::OperationalAssetReserved,
            'Generic journal reversal cannot touch an ACTIVE reserved operational asset.');
    }

    public function test_category_only_target_is_not_an_active_asset_reservation(): void
    {
        $f = $this->boundFixture();
        $chart = DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id,
            'code' => 'OUTSIDE', 'name' => 'Category only', 'type' => 'asset', 'cash_role' => 'non_cash', 'is_active' => true]);
        DB::table('operational_category_mappings')->where('category_id', $f['category'])->update(['chart_account_id' => $chart]);
        $journal = (new SaveJournalDraft)->execute($f['owner'], '2026-10-06', config('accounting.currency'), [
            ['chart_account_id' => $chart, 'debit' => '1.00', 'credit' => '0.00'],
            ['chart_account_id' => $f['charts']['equity'], 'debit' => '0.00', 'credit' => '1.00'],
        ]);
        $journal = (new PostJournalEntry)->execute($f['owner'], $journal->id);
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['state' => 'active', 'activated_at' => now()->format('Y-m-d H:i:s.u')]);
        $this->assertTrue((new ReverseJournalEntry)->execute($f['owner'], $journal->id)->isPosted());
    }

    public function test_closed_period_precedes_operational_provenance_rejection(): void
    {
        $f = $this->boundFixture('historical_backfill');
        $date = now()->toDateString();
        DB::table('accounting_periods')->insert(['user_id' => $f['owner']->id, 'start_date' => $date,
            'end_date' => $date, 'status' => 'closed', 'first_closed_at' => now()]);
        $this->rejectsUnchanged($f, $f['journal'], AccountingConflictReason::AccountingPeriodClosed,
            'Accounting period for the journal date is closed.');
    }

    public function test_old_snapshot_cannot_hide_a_newly_committed_operational_link(): void
    {
        $f = $this->linkableFixture();
        $this->configureProbeConnection('reversal_link_probe');
        DB::beginTransaction();
        try {
            $this->assertFalse(DB::table('operational_transaction_journals')->where('journal_entry_id', $f['history_journal'])->exists());
            DB::connection('reversal_link_probe')->transaction(function () use ($f): void {
                DB::connection('reversal_link_probe')->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $this->insertLink($f, 'reversal_link_probe');
            });
            $this->assertFalse(DB::table('operational_transaction_journals')->where('journal_entry_id', $f['history_journal'])->exists());
            $this->rejectsUnchanged($f, $f['history_journal'], AccountingConflictReason::OperationalJournalLinked,
                'Integrated operational journals cannot be reversed through the generic reversal action.');
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('operational_transaction_journals', ['journal_entry_id' => $f['history_journal']]);
    }

    public function test_link_commit_first_waiting_reversal_rejects_without_artifacts(): void
    {
        $f = $this->linkableFixture();
        DB::beginTransaction();
        try {
            AccountingPeriodLocks::owner($f['owner']);
            $this->insertLink($f);
            $job = $this->startWorker($f, 'reverse-journal');
            $this->assertWorkerWaiting($job);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $result = $this->waitEvent($job, 'result');
        $this->assertFalse($result['ok']);
        $this->assertSame(AccountingConflict::class, $result['class']);
        $this->assertSame('OperationalJournalLinked', $result['reason']);
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->assertDatabaseMissing('journal_entries', ['reversal_of_id' => $f['history_journal']]);
    }

    public function test_reversal_commit_first_waiting_link_obeys_existing_constraints(): void
    {
        $f = $this->linkableFixture();
        DB::beginTransaction();
        try {
            $reversal = (new ReverseJournalEntry)->execute($f['owner'], $f['history_journal']);
            $job = $this->startWorker($f, 'link-operational-journal');
            $this->assertWorkerWaiting($job);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $result = $this->waitEvent($job, 'result');
        // Current link constraints permit an original posted journal even when a
        // reversal exists. D2b preserves that behavior; it does not add a link writer.
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_transaction_journals', ['transaction_id' => $f['transaction'], 'journal_entry_id' => $f['history_journal']]);
        $this->assertDatabaseHas('journal_entries', ['id' => $reversal->id, 'status' => 'posted', 'reversal_of_id' => $f['history_journal']]);
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $reversal->id)->count());
        $this->assertSame(1, DB::table('journal_entries')->where('reversal_of_id', $f['history_journal'])->count());
        $this->assertSame(3, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
    }

    public function test_link_guard_locks_only_current_original_provenance_at_the_declared_stage(): void
    {
        $f = $this->boundFixture('historical_backfill');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->rejectsUnchanged($f, $f['journal'], AccountingConflictReason::OperationalJournalLinked,
                'Integrated operational journals cannot be reversed through the generic reversal action.');
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')
                || str_contains($q['query'], 'lock in share mode')));
            $tables = ['users', 'accounting_periods', 'fiscal_year_closes', 'fiscal_year_closes',
                'operational_accounting_integrations', 'operational_accounting_account_scope', 'operational_accounting_category_scope',
                'operational_account_mappings', 'operational_category_mappings', 'chart_of_accounts', 'journal_entries', 'journal_lines',
                'journal_line_allocations', 'cash_flow_journal_completions', 'opening_balance_batches', 'operational_transaction_journals'];
            $this->assertCount(count($tables), $locks);
            foreach ($tables as $i => $table) {
                $this->assertStringContainsString('from `'.$table.'`', $locks[$i]['query']);
            }
            $last = $locks[count($locks) - 1];
            $this->assertSame([$f['owner']->id, $f['journal']], $last['bindings']);
            $this->assertStringContainsString('select `transaction_id`', $last['query']);
            $this->assertStringContainsString('lock in share mode', $last['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_link_conflict_has_explicit_page_presentation(): void
    {
        $this->assertSame('لا يمكن عكس القيود المرتبطة بمعاملات تشغيلية من خلال إجراء العكس العام.',
            (new AccountingConflictPresentation)->pageMutation(new AccountingConflict(reason: AccountingConflictReason::OperationalJournalLinked)));
    }
}
