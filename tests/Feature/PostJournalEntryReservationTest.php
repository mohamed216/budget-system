<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Actions\PostJournalEntry;
use App\Accounting\Actions\SaveJournalDraft;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use App\Http\Presenters\AccountingConflictPresentation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class PostJournalEntryReservationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function extraAsset(array $f): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id,
            'code' => 'OUTSIDE', 'name' => 'Outside scope', 'type' => 'asset', 'cash_role' => 'non_cash', 'is_active' => true]);
    }

    private function draft(array $f, ?int $chart = null, string $shape = 'normal'): int
    {
        $chart ??= $f['charts']['asset'];
        $lines = [
            ['chart_account_id' => $chart, 'debit' => '1.00', 'credit' => '0.00'],
            ['chart_account_id' => $shape === 'offsetting' ? $chart : $f['charts']['equity'], 'debit' => '0.00', 'credit' => '1.00'],
        ];
        if ($shape === 'multiline') {
            $lines[1]['credit'] = '2.00';
            $lines[] = ['chart_account_id' => $f['charts']['equity'], 'debit' => '1.00', 'credit' => '0.00'];
        }

        return (new SaveJournalDraft)->execute($f['owner'], '2026-10-12', config('accounting.currency'), $lines)->id;
    }

    /** Synthetic test cutover only; D4 activation is not implemented. */
    private function activate(array $f): void
    {
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['state' => 'active', 'activated_at' => now()->format('Y-m-d H:i:s.u')]);
    }

    private function rejectsUnchanged(array $f, int $journal): void
    {
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostJournalEntry)->execute($f['owner'], $journal);
            $this->fail('Reserved asset publication accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::OperationalAssetReserved, $exception->reason);
            $this->assertSame('Generic journal posting cannot touch an ACTIVE reserved operational asset.', $exception->getMessage());
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->assertDatabaseHas('journal_entries', ['id' => $journal, 'status' => 'draft', 'posted_at' => null]);
        $this->assertDatabaseMissing('cash_flow_journal_completions', ['journal_entry_id' => $journal]);
    }

    public static function reservedShapes(): array
    {
        return [['normal'], ['disabled'], ['offsetting'], ['multiline'], ['cash']];
    }

    #[DataProvider('reservedShapes')]
    public function test_selected_mapped_asset_rejects_before_activity_cashflow_or_evidence_work(string $shape): void
    {
        $f = $this->boundFixture();
        $journal = $this->draft($f, shape: $shape);
        if ($shape === 'cash') {
            // Deliberate staleness before synthetic ACTIVE, never an ACTIVE mutation.
            DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['cash_role' => 'cash']);
        }
        $this->activate($f);
        if ($shape === 'disabled') {
            DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['is_active' => false]);
        }
        $this->rejectsUnchanged($f, $journal);
    }

    public function test_unrelated_asset_and_category_only_mapping_do_not_reserve_assets(): void
    {
        $f = $this->boundFixture();
        $chart = $this->extraAsset($f);
        // This intentionally unusual category mapping proves it is not account authority.
        DB::table('operational_category_mappings')->where('category_id', $f['category'])->update(['chart_account_id' => $chart]);
        $this->activate($f);
        $before = (array) DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $journal = $this->draft($f, $chart);
        $this->assertTrue((new PostJournalEntry)->execute($f['owner'], $journal)->isPosted());
        $this->assertSame($before, (array) DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    public function test_unselected_account_mapping_does_not_reserve_an_asset(): void
    {
        $f = $this->boundFixture();
        $chart = $this->extraAsset($f);
        $account = DB::table('accounts')->insertGetId(['user_id' => $f['owner']->id, 'name' => 'Unselected',
            'type' => 'bank', 'balance' => '0.00', 'currency' => config('accounting.currency')]);
        DB::table('operational_account_mappings')->insert(['user_id' => $f['owner']->id, 'account_id' => $account, 'chart_account_id' => $chart]);
        $this->activate($f);
        $this->assertTrue((new PostJournalEntry)->execute($f['owner'], $this->draft($f, $chart))->isPosted());
    }

    public function test_pre_active_mapped_asset_publication_and_posted_retry_remain_valid(): void
    {
        $f = $this->boundFixture();
        $journal = $this->draft($f);
        $posted = (new PostJournalEntry)->execute($f['owner'], $journal);
        $this->assertTrue($posted->isPosted());
        // Posting invalidates the old binding. Use a fresh owner for the ACTIVE retry fixture.
        $retry = $this->boundFixture();
        $journal = $retry['journal'];
        $this->activate($retry);
        DB::table('chart_of_accounts')->where('id', $retry['charts']['asset'])->update(['is_active' => false]);
        DB::table('accounting_periods')->insert(['user_id' => $retry['owner']->id, 'start_date' => '2026-10-01',
            'end_date' => '2026-10-31', 'status' => 'closed', 'first_closed_at' => now()]);
        config(['accounting.currency' => config('accounting.currency') === 'USD' ? 'SAR' : 'USD']);
        $before = $this->allFixtureRows($retry['owner']->id);
        $this->assertSame($journal, (new PostJournalEntry)->execute($retry['owner'], $journal)->id);
        $this->assertSame($before, $this->allFixtureRows($retry['owner']->id));
    }

    public function test_closed_period_precedence_is_preserved_for_reserved_drafts(): void
    {
        $f = $this->boundFixture();
        $journal = $this->draft($f);
        $this->activate($f);
        DB::table('accounting_periods')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-12',
            'end_date' => '2026-10-12', 'status' => 'closed', 'first_closed_at' => now()]);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostJournalEntry)->execute($f['owner'], $journal);
            $this->fail('Closed period accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::AccountingPeriodClosed, $exception->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_old_snapshot_cannot_hide_a_newly_committed_active_reservation(): void
    {
        $f = $this->boundFixture();
        $journal = $this->draft($f);
        $outside = $this->extraAsset($f);
        DB::table('operational_account_mappings')->where('account_id', $f['account'])->update(['chart_account_id' => $outside]);
        $this->configureProbeConnection('reservation_probe');
        DB::beginTransaction();
        try {
            $this->assertSame('reconciling', DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('state'));
            $this->assertSame($outside, DB::table('operational_account_mappings')->where('account_id', $f['account'])->value('chart_account_id'));
            DB::connection('reservation_probe')->transaction(function () use ($f): void {
                $probe = DB::connection('reservation_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_account_scope')->where('user_id', $f['owner']->id)->sharedLock()->get();
                $probe->table('operational_account_mappings')->where('account_id', $f['account'])->update(['chart_account_id' => $f['charts']['asset']]);
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
                    ->update(['state' => 'active', 'activated_at' => now()->format('Y-m-d H:i:s.u')]);
            });
            $this->assertSame('reconciling', DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('state'));
            $this->assertSame($outside, DB::table('operational_account_mappings')->where('account_id', $f['account'])->value('chart_account_id'));
            $this->rejectsUnchanged($f, $journal);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'active']);
    }

    public function test_active_commit_first_waiting_publication_rejects(): void
    {
        $f = $this->boundFixture();
        $f['history_journal'] = $this->draft($f);
        DB::beginTransaction();
        try {
            AccountingPeriodLocks::owner($f['owner']);
            $this->activate($f);
            $job = $this->startWorker($f, 'post-journal');
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
        $this->assertSame('OperationalAssetReserved', $result['reason']);
        $this->assertSame(AccountingConflict::class, $result['class']);
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_post_commit_first_waiting_mapping_change_preserves_complete_posting(): void
    {
        $f = $this->boundFixture();
        $f['history_journal'] = $this->draft($f);
        $f['mapping_target'] = $this->extraAsset($f);
        DB::beginTransaction();
        try {
            $this->assertTrue((new PostJournalEntry)->execute($f['owner'], $f['history_journal'])->isPosted());
            $job = $this->startWorker($f, 'map-account');
            $this->assertWorkerWaiting($job);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertDatabaseHas('operational_account_mappings', ['account_id' => $f['account'], 'chart_account_id' => $f['mapping_target']]);
        $this->assertDatabaseHas('journal_entries', ['id' => $f['history_journal'], 'status' => 'posted']);
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $f['history_journal'])->count());
        $this->assertSame(2, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_waiting_second_publication_returns_the_same_posted_journal(): void
    {
        $f = $this->boundFixture();
        $f['history_journal'] = $this->draft($f);
        DB::beginTransaction();
        try {
            $posted = (new PostJournalEntry)->execute($f['owner'], $f['history_journal']);
            $job = $this->startWorker($f, 'post-journal');
            $this->assertWorkerWaiting($job);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $before = $this->allFixtureRows($f['owner']->id);
        $result = $this->waitEvent($job, 'result');
        $this->assertTrue($result['ok'], $result['message'] ?? '');
        $this->assertSame($posted->id, $result['result']);
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_reservation_authority_is_loaded_once_before_chart_header_and_line_locks(): void
    {
        $f = $this->boundFixture();
        $journal = $this->draft($f);
        $this->activate($f);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->rejectsUnchanged($f, $journal);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')
                || str_contains($q['query'], 'lock in share mode')));
            $tables = ['users', 'accounting_periods', 'fiscal_year_closes', 'operational_accounting_integrations',
                'operational_accounting_account_scope', 'operational_accounting_category_scope',
                'operational_account_mappings', 'operational_category_mappings', 'chart_of_accounts', 'journal_entries', 'journal_lines'];
            $this->assertCount(count($tables), $locks);
            foreach ($tables as $i => $table) {
                $this->assertStringContainsString('from `'.$table.'`', $locks[$i]['query']);
            }
            $this->assertStringContainsString('order by `id` asc', $locks[8]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_reservation_conflict_has_an_explicit_page_message(): void
    {
        $message = (new AccountingConflictPresentation)->pageMutation(new AccountingConflict(reason: AccountingConflictReason::OperationalAssetReserved));
        $this->assertSame('لا يمكن ترحيل قيد عام يمس حساب أصل محجوز للمحاسبة التشغيلية النشطة.', $message);
    }
}
