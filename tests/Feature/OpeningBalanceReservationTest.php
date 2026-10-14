<?php

namespace Tests\Feature;

use App\Accounting\AccountingPeriodLocks;
use App\Accounting\Actions\PostOpeningBalanceBatch;
use App\Accounting\Exceptions\AccountingConflict;
use App\Accounting\Exceptions\AccountingConflictReason;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OpeningBalanceReservationTest extends TestCase
{
    use InteractsWithReconciliationFixtures;

    private function extraAsset(array $f): int
    {
        return DB::table('chart_of_accounts')->insertGetId(['user_id' => $f['owner']->id,
            'code' => 'OUTSIDE', 'name' => 'Outside scope', 'type' => 'asset', 'cash_role' => 'non_cash', 'is_active' => true]);
    }

    private function openingDraft(array $f, ?int $chart = null, bool $multiline = false): int
    {
        $batch = DB::table('opening_balance_batches')->insertGetId(['user_id' => $f['owner']->id,
            'opening_date' => '2026-10-12', 'currency' => config('accounting.currency')]);
        $base = ['user_id' => $f['owner']->id, 'batch_id' => $batch];
        $lines = [
            $base + ['chart_account_id' => $chart ?? $f['charts']['asset'], 'debit' => '1.00', 'credit' => '0.00'],
            $base + ['chart_account_id' => $f['charts']['equity'], 'debit' => '0.00', 'credit' => $multiline ? '2.00' : '1.00'],
        ];
        if ($multiline) {
            $lines[] = $base + ['chart_account_id' => $this->extraAsset($f), 'debit' => '1.00', 'credit' => '0.00'];
        }
        DB::table('opening_balance_lines')->insert($lines);

        return $batch;
    }

    /** Synthetic fixture state only; no activation action is added. */
    private function state(array $f, string $state): void
    {
        $changes = ['state' => $state];
        if ($state === 'active') {
            $changes['activated_at'] = now()->format('Y-m-d H:i:s.u');
        } elseif ($state === 'legacy') {
            $changes += ['reconciliation_reference' => null, 'reconciled_at' => null];
        }
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update($changes);
    }

    private function rejectsUnchanged(array $f, int $batch): void
    {
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostOpeningBalanceBatch)->execute($f['owner'], $batch);
            $this->fail('Reserved opening balance publication accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::OperationalAssetReserved, $exception->reason);
            $this->assertSame('Opening balance posting cannot touch an ACTIVE reserved operational asset.', $exception->getMessage());
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
        $this->assertDatabaseHas('opening_balance_batches', ['id' => $batch, 'status' => 'draft', 'journal_entry_id' => null, 'posted_at' => null]);
    }

    public static function reservedCases(): array
    {
        return [['normal'], ['disabled'], ['multiline']];
    }

    #[DataProvider('reservedCases')]
    public function test_new_reserved_asset_posting_rejects_atomically_before_generic_activity_validation(string $kind): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f, multiline: $kind === 'multiline');
        $this->state($f, 'active');
        if ($kind === 'disabled') {
            DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['is_active' => false]);
        }
        $this->rejectsUnchanged($f, $batch);
    }

    public function test_unrelated_asset_and_category_only_target_remain_allowed(): void
    {
        $f = $this->boundFixture();
        $chart = $this->extraAsset($f);
        DB::table('operational_category_mappings')->where('category_id', $f['category'])->update(['chart_account_id' => $chart]);
        $this->state($f, 'active');
        $before = (array) DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        $this->assertTrue((new PostOpeningBalanceBatch)->execute($f['owner'], $this->openingDraft($f, $chart))->isPosted());
        $this->assertSame($before, (array) DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first());
    }

    public function test_unselected_account_mapping_does_not_reserve_the_target(): void
    {
        $f = $this->boundFixture();
        $chart = $this->extraAsset($f);
        $account = DB::table('accounts')->insertGetId(['user_id' => $f['owner']->id, 'name' => 'Unselected',
            'type' => 'bank', 'balance' => '0.00', 'currency' => config('accounting.currency')]);
        DB::table('operational_account_mappings')->insert(['user_id' => $f['owner']->id, 'account_id' => $account, 'chart_account_id' => $chart]);
        $this->state($f, 'active');
        $this->assertTrue((new PostOpeningBalanceBatch)->execute($f['owner'], $this->openingDraft($f, $chart))->isPosted());
    }

    public static function preActiveStates(): array
    {
        return [['legacy'], ['reconciling'], ['ready']];
    }

    #[DataProvider('preActiveStates')]
    public function test_pre_active_selected_mapped_asset_posting_remains_allowed(string $state): void
    {
        $f = $this->boundFixture();
        $this->state($f, $state);
        $posted = (new PostOpeningBalanceBatch)->execute($f['owner'], $this->openingDraft($f));
        $this->assertTrue($posted->isPosted());
        $this->assertTrue($posted->journalEntry->isPosted());
        $this->assertCount(2, $posted->journalEntry->lines);
    }

    public function test_posted_retry_survives_later_reservation_disabling_closure_and_config_change(): void
    {
        $f = $this->boundFixture();
        $chart = $this->extraAsset($f);
        $posted = (new PostOpeningBalanceBatch)->execute($f['owner'], $this->openingDraft($f, $chart));
        // Establish the new live reservation only after this unrelated posting.
        // This is synthetic cutover setup, not an authoritative D4 activation.
        DB::table('operational_account_mappings')->where('account_id', $f['account'])->update(['chart_account_id' => $chart]);
        $this->state($f, 'active');
        DB::table('chart_of_accounts')->where('id', $chart)->update(['is_active' => false]);
        DB::table('accounting_periods')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-12',
            'end_date' => '2026-10-12', 'status' => 'closed', 'first_closed_at' => now()]);
        config(['accounting.currency' => config('accounting.currency') === 'USD' ? 'SAR' : 'USD']);
        $before = $this->allFixtureRows($f['owner']->id);
        $retry = (new PostOpeningBalanceBatch)->execute($f['owner'], $posted->id);
        $this->assertSame($posted->id, $retry->id);
        $this->assertSame($posted->journal_entry_id, $retry->journal_entry_id);
        $this->assertSame($posted->posted_at->format('Y-m-d H:i:s.u'), $retry->posted_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_corrupt_posted_link_is_not_masked_by_reservation(): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f);
        $draftJournal = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id,
            'entry_date' => '2026-10-12', 'currency' => config('accounting.currency')]);
        DB::table('opening_balance_batches')->where('id', $batch)->update(['status' => 'posted',
            'journal_entry_id' => $draftJournal, 'posted_at' => now()->format('Y-m-d H:i:s.u')]);
        $this->state($f, 'active');
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostOpeningBalanceBatch)->execute($f['owner'], $batch);
            $this->fail('Corrupt posted linkage accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertSame('Opening balance journal link is inconsistent.', $exception->getMessage());
            $this->assertNull($exception->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_closed_period_precedence_remains_before_fresh_reservation_rejection(): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f);
        $this->state($f, 'active');
        DB::table('accounting_periods')->insert(['user_id' => $f['owner']->id, 'start_date' => '2026-10-12',
            'end_date' => '2026-10-12', 'status' => 'closed', 'first_closed_at' => now()]);
        $before = $this->allFixtureRows($f['owner']->id);
        try {
            (new PostOpeningBalanceBatch)->execute($f['owner'], $batch);
            $this->fail('Closed period accepted.');
        } catch (AccountingConflict $exception) {
            $this->assertSame(AccountingConflictReason::AccountingPeriodClosed, $exception->reason);
        }
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_old_snapshot_cannot_hide_newly_committed_active_gate_and_mapping(): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f);
        $outside = $this->extraAsset($f);
        DB::table('operational_account_mappings')->where('account_id', $f['account'])->update(['chart_account_id' => $outside]);
        $this->configureProbeConnection('opening_reservation_probe');
        DB::beginTransaction();
        try {
            $this->assertSame('reconciling', DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('state'));
            $this->assertSame($outside, DB::table('operational_account_mappings')->where('account_id', $f['account'])->value('chart_account_id'));
            DB::connection('opening_reservation_probe')->transaction(function () use ($f): void {
                $probe = DB::connection('opening_reservation_probe');
                $probe->table('users')->where('id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->lockForUpdate()->first();
                $probe->table('operational_accounting_account_scope')->where('user_id', $f['owner']->id)->sharedLock()->get();
                $probe->table('operational_account_mappings')->where('account_id', $f['account'])->update(['chart_account_id' => $f['charts']['asset']]);
                $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
                    ->update(['state' => 'active', 'activated_at' => now()->format('Y-m-d H:i:s.u')]);
            });
            $this->assertSame('reconciling', DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('state'));
            $this->assertSame($outside, DB::table('operational_account_mappings')->where('account_id', $f['account'])->value('chart_account_id'));
            $this->rejectsUnchanged($f, $batch);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id, 'state' => 'active']);
    }

    public function test_active_commit_first_waiting_new_opening_post_rejects(): void
    {
        $f = $this->boundFixture();
        $f['opening_batch'] = $this->openingDraft($f);
        DB::beginTransaction();
        try {
            AccountingPeriodLocks::owner($f['owner']);
            $this->state($f, 'active');
            $job = $this->startWorker($f, 'post-opening');
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
        $this->assertSame('OperationalAssetReserved', $result['reason']);
        $this->assertSame($before, $this->allFixtureRows($f['owner']->id));
    }

    public function test_opening_commit_first_waiting_mapping_change_preserves_complete_posting(): void
    {
        $f = $this->boundFixture();
        $f['opening_batch'] = $this->openingDraft($f);
        $f['mapping_target'] = $this->extraAsset($f);
        DB::beginTransaction();
        try {
            $posted = (new PostOpeningBalanceBatch)->execute($f['owner'], $f['opening_batch']);
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
        $this->assertDatabaseHas('opening_balance_batches', ['id' => $f['opening_batch'], 'status' => 'posted', 'journal_entry_id' => $posted->journal_entry_id]);
        $this->assertDatabaseHas('journal_entries', ['id' => $posted->journal_entry_id, 'status' => 'posted']);
        $this->assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $posted->journal_entry_id)->count());
        $this->assertSame(2, DB::table('journal_entries')->where('user_id', $f['owner']->id)->count());
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $f['owner']->id,
            'state' => 'reconciling', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_reservation_loads_once_before_charts_and_opening_sources(): void
    {
        $f = $this->boundFixture();
        $batch = $this->openingDraft($f);
        $this->state($f, 'active');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->rejectsUnchanged($f, $batch);
            $locks = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')
                || str_contains($q['query'], 'lock in share mode')));
            $tables = ['users', 'accounting_periods', 'fiscal_year_closes', 'operational_accounting_integrations',
                'operational_accounting_account_scope', 'operational_accounting_category_scope',
                'operational_account_mappings', 'operational_category_mappings', 'chart_of_accounts', 'opening_balance_batches', 'opening_balance_lines'];
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
}
