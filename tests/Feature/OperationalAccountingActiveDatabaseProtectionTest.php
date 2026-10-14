<?php

namespace Tests\Feature;

use App\Accounting\Actions\PostJournalEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithActiveProtectionTriggers;
use Tests\Concerns\InteractsWithReconciliationFixtures;
use Tests\TestCase;

class OperationalAccountingActiveDatabaseProtectionTest extends TestCase
{
    use InteractsWithActiveProtectionTriggers;
    use InteractsWithReconciliationFixtures {
        setUp as private setUpFixtures;
    }

    protected function setUp(): void
    {
        $this->setUpFixtures();
        // Isolated tests only: recover known leftovers from an interrupted test
        // run, then prove all authoritative bodies exist before probing guards.
        $this->restoreActiveProtectionTriggers();
        $this->assertActiveProtectionTriggersInstalledExactly();
    }

    /** Synthetic isolated fixtures, not the future activation workflow. */
    private function fixture(string $state = 'active'): array
    {
        $owner = User::factory()->create();
        $other = User::factory()->create()->id;
        $this->owners[] = $owner->id;
        $this->owners[] = $other;
        $account = DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'name' => 'Selected', 'type' => 'bank', 'balance' => '100.00', 'currency' => 'SAR']);
        $extraAccount = DB::table('accounts')->insertGetId(['user_id' => $owner->id, 'name' => 'Unselected', 'type' => 'bank', 'balance' => '0.00', 'currency' => 'SAR']);
        $category = DB::table('categories')->insertGetId(['user_id' => $owner->id, 'name' => 'Selected', 'type' => 'income']);
        $extraCategory = DB::table('categories')->insertGetId(['user_id' => $owner->id, 'name' => 'Unselected', 'type' => 'income']);
        $charts = [];
        foreach (['asset' => 'asset', 'revenue' => 'revenue', 'alternate' => 'asset', 'reviewOnly' => 'asset', 'unrelated' => 'asset'] as $key => $type) {
            $charts[$key] = DB::table('chart_of_accounts')->insertGetId(['user_id' => $owner->id, 'code' => $key,
                'name' => $key, 'type' => $type, 'is_active' => true, 'cash_role' => 'non_cash']);
        }
        DB::table('operational_accounting_account_scope')->insert(['user_id' => $owner->id, 'account_id' => $account]);
        DB::table('operational_accounting_category_scope')->insert(['user_id' => $owner->id, 'category_id' => $category]);
        DB::table('operational_account_mappings')->insert(['user_id' => $owner->id, 'account_id' => $account, 'chart_account_id' => $charts['asset']]);
        DB::table('operational_category_mappings')->insert(['user_id' => $owner->id, 'category_id' => $category,
            'chart_account_id' => $charts['revenue'], 'cash_flow_activity' => 'operating', 'reviewed_by' => $owner->id, 'reviewed_at' => '2026-10-10 09:00:00.123456']);
        $reference = (string) Str::uuid();
        $review = DB::table('operational_reconciliation_reviews')->insertGetId(['user_id' => $owner->id,
            'reference' => $reference, 'historical_transaction_strategy' => 'balance_only', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 0, 'scope_reviewed_at' => '2026-10-10 09:00:00',
            'balance_only_disclosure_code' => 'balance_only_legacy_history_v1', 'balance_only_disclosure_text' => 'Isolated trigger fixture.', 'created_at' => '2026-10-10 08:00:00']);
        DB::table('operational_reconciliation_charts')->insert(['review_id' => $review, 'user_id' => $owner->id,
            'chart_account_id' => $charts['reviewOnly'], 'type_snapshot' => 'asset', 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => true]);
        DB::table('operational_reconciliation_reviews')->where('id', $review)->update(['reviewed_by' => $owner->id, 'completed_at' => '2026-10-10 10:00:00.123456']);
        DB::table('operational_accounting_integrations')->insert(['user_id' => $owner->id, 'state' => $state,
            'accounting_currency' => 'SAR', 'historical_transaction_strategy' => 'balance_only', 'transaction_high_water_id' => 0,
            'scope_reviewed_at' => '2026-10-10 09:00:00', 'reconciliation_reference' => $state === 'legacy' ? null : $reference,
            'reconciled_at' => $state === 'legacy' ? null : '2026-10-10 10:00:00.123456',
            'activated_at' => $state === 'active' ? '2026-10-10 11:00:00.654321' : null]);

        return compact('owner', 'other', 'account', 'extraAccount', 'category', 'extraCategory', 'charts', 'review', 'reference');
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Raw SQL bypassed ACTIVE protections.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
        }
    }

    public static function integrationChanges(): array
    {
        return [
            'owner' => [['user_id' => 'other']], 'fallback' => [['state' => 'reconciling']],
            'activation time' => [['activated_at' => '2026-10-10 11:00:00.654322']], 'clear activation' => [['activated_at' => null]],
            'currency' => [['accounting_currency' => 'USD']], 'currency spelling' => [['accounting_currency' => 'sar']],
            'strategy' => [['historical_transaction_strategy' => 'historical_backfill']], 'boundary' => [['transaction_high_water_id' => 1]],
            'scope time' => [['scope_reviewed_at' => '2026-10-10 09:00:01']], 'binding time' => [['reconciled_at' => '2026-10-10 10:00:00.123457']],
            'clear binding' => [['reconciliation_reference' => null, 'reconciled_at' => null]],
        ];
    }

    #[DataProvider('integrationChanges')]
    public function test_active_integration_identity_is_immutable(array $changes): void
    {
        $f = $this->fixture();
        if (($changes['user_id'] ?? null) === 'other') {
            $changes['user_id'] = $f['other'];
        }
        $query = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id);
        $before = (array) $query->first();
        $this->rejects(fn () => $query->update($changes));
        $this->assertSame($before, (array) $query->first());
    }

    public function test_active_delete_and_valid_completed_binding_substitution_reject_but_noop_passes(): void
    {
        $f = $this->fixture();
        $query = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id);
        $this->rejects(fn () => $query->delete());
        $reference = (string) Str::uuid();
        $row = (array) DB::table('operational_reconciliation_reviews')->where('id', $f['review'])->first();
        unset($row['id']);
        $row['reference'] = $reference;
        $row['reviewed_by'] = $row['completed_at'] = null;
        $id = DB::table('operational_reconciliation_reviews')->insertGetId($row);
        DB::table('operational_reconciliation_reviews')->where('id', $id)->update(['reviewed_by' => $f['owner']->id, 'completed_at' => '2026-10-10 10:00:00.123456']);
        $this->rejects(fn () => $query->update(['reconciliation_reference' => $reference]));
        $query->update((array) $query->first());
        $this->assertSame($f['reference'], $query->value('reconciliation_reference'));
    }

    public function test_activation_timestamp_is_one_way_even_before_active(): void
    {
        $f = $this->fixture('reconciling');
        $query = DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id);
        $query->update(['activated_at' => '2026-10-10 11:00:00.123456']);
        $this->rejects(fn () => $query->update(['activated_at' => null]));
        $query->update(['activated_at' => '2026-10-10 11:00:00.123456']);
    }

    public static function kinds(): array
    {
        return [['account'], ['category']];
    }

    #[DataProvider('kinds')]
    public function test_scopes_reject_insert_delete_identity_and_audit_changes(string $kind): void
    {
        $f = $this->fixture();
        $table = 'operational_accounting_'.$kind.'_scope';
        $query = DB::table($table)->where($kind.'_id', $f[$kind]);
        $this->rejects(fn () => DB::table($table)->insert(['user_id' => $f['owner']->id, $kind.'_id' => $f['extra'.ucfirst($kind)]]));
        $this->rejects(fn () => $query->delete());
        $this->rejects(fn () => $query->update([$kind.'_id' => $f['extra'.ucfirst($kind)]]));
        $this->rejects(fn () => $query->update(['user_id' => $f['other']]));
        $this->rejects(fn () => $query->update(['updated_at' => '2026-10-10 12:00:00']));
        $query->update((array) $query->first());
        DB::table($table)->insert(['user_id' => $f['other'], $kind.'_id' => DB::table($kind === 'account' ? 'accounts' : 'categories')->insertGetId(
            $kind === 'account' ? ['user_id' => $f['other'], 'name' => 'Other', 'type' => 'bank', 'balance' => '0.00', 'currency' => 'SAR']
                : ['user_id' => $f['other'], 'name' => 'Other', 'type' => 'income'])]);
        $this->rejects(fn () => DB::table($table)->where('user_id', $f['other'])->update(['user_id' => $f['owner']->id, $kind.'_id' => $f['extra'.ucfirst($kind)]]));
        DB::table($table)->where('user_id', $f['other'])->delete();
    }

    #[DataProvider('kinds')]
    public function test_unused_participating_mappings_are_frozen_but_unselected_mappings_remain_editable(string $kind): void
    {
        $f = $this->fixture();
        $table = 'operational_'.$kind.'_mappings';
        $query = DB::table($table)->where($kind.'_id', $f[$kind]);
        $this->assertDatabaseCount('operational_transaction_journals', 0);
        $this->rejects(fn () => $query->update(['chart_account_id' => $f['charts']['alternate']]));
        $this->rejects(fn () => $query->delete());
        $this->rejects(fn () => $query->update(['user_id' => $f['other']]));
        $this->rejects(fn () => $query->update([$kind.'_id' => $f['extra'.ucfirst($kind)]]));
        if ($kind === 'category') {
            foreach (['cash_flow_activity' => 'investing', 'reviewed_by' => $f['other'], 'reviewed_at' => '2026-10-10 09:00:00.123457'] as $field => $value) {
                $this->rejects(fn () => $query->update([$field => $value]));
            }
            $this->rejects(fn () => $query->update(['cash_flow_activity' => null, 'reviewed_by' => null, 'reviewed_at' => null]));
        }
        $query->update((array) $query->first());
        DB::table($table)->insert(['user_id' => $f['owner']->id, $kind.'_id' => $f['extra'.ucfirst($kind)], 'chart_account_id' => $f['charts']['alternate']]);
        DB::table($table)->where($kind.'_id', $f['extra'.ucfirst($kind)])->update(['chart_account_id' => $f['charts']['unrelated']]);
        DB::table($table)->where($kind.'_id', $f['extra'.ucfirst($kind)])->delete();
    }

    #[DataProvider('kinds')]
    public function test_missing_selected_mapping_cannot_be_inserted_after_active(string $kind): void
    {
        $f = $this->fixture('ready');
        DB::table('operational_'.$kind.'_mappings')->where($kind.'_id', $f[$kind])->delete();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => '2026-10-10 13:00:00']);
        $this->rejects(fn () => DB::table('operational_'.$kind.'_mappings')->insert(['user_id' => $f['owner']->id,
            $kind.'_id' => $f[$kind], 'chart_account_id' => $f['charts'][$kind === 'account' ? 'asset' : 'revenue']]));
    }

    public function test_participating_source_facts_are_closed_and_display_changes_are_allowed(): void
    {
        $f = $this->fixture();
        foreach (['balance' => '100.01', 'currency' => 'USD', 'user_id' => $f['other']] as $field => $value) {
            $this->rejects(fn () => DB::table('accounts')->where('id', $f['account'])->update([$field => $value]));
        }
        $this->rejects(fn () => DB::table('accounts')->where('id', $f['account'])->delete());
        foreach (['type' => 'expense', 'user_id' => $f['other']] as $field => $value) {
            $this->rejects(fn () => DB::table('categories')->where('id', $f['category'])->update([$field => $value]));
        }
        $this->rejects(fn () => DB::table('categories')->where('id', $f['category'])->delete());
        DB::table('accounts')->where('id', $f['account'])->update(['name' => 'Display', 'balance' => '100.00']);
        DB::table('categories')->where('id', $f['category'])->update(['name' => 'Display']);
        DB::table('accounts')->where('id', $f['extraAccount'])->update(['balance' => '1.00', 'currency' => 'USD', 'user_id' => $f['other']]);
        DB::table('accounts')->where('id', $f['extraAccount'])->delete();
        DB::table('categories')->where('id', $f['extraCategory'])->update(['type' => 'expense', 'user_id' => $f['other']]);
        DB::table('categories')->where('id', $f['extraCategory'])->delete();
    }

    public static function preActive(): array
    {
        return [['legacy'], ['reconciling'], ['ready']];
    }

    #[DataProvider('preActive')]
    public function test_pre_active_source_mapping_and_scope_behavior_is_preserved(string $state): void
    {
        $f = $this->fixture($state);
        DB::table('accounts')->where('id', $f['account'])->update(['balance' => '102.50', 'currency' => 'USD']);
        DB::table('categories')->where('id', $f['category'])->update(['type' => 'expense']);
        DB::table('operational_account_mappings')->where('account_id', $f['account'])->update(['chart_account_id' => $f['charts']['alternate']]);
        DB::table('operational_category_mappings')->where('category_id', $f['category'])->update(['cash_flow_activity' => 'investing']);
        DB::table('operational_accounting_account_scope')->where('account_id', $f['account'])->delete();
        DB::table('operational_accounting_category_scope')->insert(['user_id' => $f['owner']->id, 'category_id' => $f['extraCategory']]);
        $this->assertSame('102.50', DB::table('accounts')->where('id', $f['account'])->value('balance'));
    }

    public function test_mapped_and_bound_review_charts_freeze_semantics_but_not_activity(): void
    {
        $f = $this->fixture();
        foreach (['asset', 'revenue', 'reviewOnly'] as $key) {
            $query = DB::table('chart_of_accounts')->where('id', $f['charts'][$key]);
            foreach (['type' => 'equity', 'user_id' => $f['other'], 'cash_role' => null] as $field => $value) {
                $this->rejects(fn () => $query->update([$field => $value]));
            }
            $this->rejects(fn () => $query->delete());
            $query->update(['is_active' => false, 'name' => 'Display']);
            $query->update(['is_active' => true]);
        }
        DB::table('chart_of_accounts')->where('id', $f['charts']['unrelated'])->update(['type' => 'equity', 'user_id' => $f['other']]);
        DB::table('chart_of_accounts')->where('id', $f['charts']['unrelated'])->delete();
    }

    private function draft(array $f, int $chart): int
    {
        $journal = DB::table('journal_entries')->insertGetId(['user_id' => $f['owner']->id, 'entry_date' => '2026-10-10', 'currency' => 'SAR']);
        foreach ([['1.00', '0.00'], ['0.00', '1.00']] as $index => [$debit, $credit]) {
            DB::table('journal_lines')->insert(['user_id' => $f['owner']->id, 'journal_entry_id' => $journal,
                'chart_account_id' => $chart, 'line_number' => $index + 1, 'debit' => $debit, 'credit' => $credit]);
        }

        return $journal;
    }

    private function publish(int $id): void
    {
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted', 'posted_at' => '2026-10-10 12:00:00']);
    }

    public function test_reserved_publication_rejects_offsetting_lines_and_disabled_asset(): void
    {
        $f = $this->fixture();
        $id = $this->draft($f, $f['charts']['asset']);
        DB::table('journal_entries')->where('id', $id)->update(['reference' => 'Draft editing remains allowed']);
        $this->rejects(fn () => $this->publish($id));
        DB::table('chart_of_accounts')->where('id', $f['charts']['asset'])->update(['is_active' => false]);
        $this->rejects(fn () => $this->publish($id));
        $this->assertDatabaseHas('journal_entries', ['id' => $id, 'status' => 'draft', 'posted_at' => null]);
        $this->publish($this->draft($f, $f['charts']['unrelated']));
    }

    public function test_non_active_publication_and_existing_posted_retry_remain_valid(): void
    {
        $f = $this->fixture('ready');
        $id = $this->draft($f, $f['charts']['asset']);
        $this->publish($id);
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->update(['state' => 'active', 'activated_at' => '2026-10-10 13:00:00.123456']);
        $before = (array) DB::table('journal_entries')->where('id', $id)->first();
        $this->assertSame($id, (new PostJournalEntry)->execute($f['owner'], $id)->id);
        $this->assertSame($before, (array) DB::table('journal_entries')->where('id', $id)->first());
        // Existing posted immutability remains stricter than a raw SQL no-op.
        $this->rejects(fn () => DB::table('journal_entries')->where('id', $id)->update(['status' => 'posted']));
    }

    public function test_old_snapshot_cannot_hide_a_newly_committed_active_gate(): void
    {
        $f = $this->fixture('ready');
        $name = 'active_protection_probe';
        config(['database.connections.'.$name => config('database.connections.mysql_testing')]);
        $this->probeConnections[] = $name;
        DB::beginTransaction();
        DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->first();
        DB::connection($name)->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)
            ->update(['state' => 'active', 'activated_at' => '2026-10-10 13:00:00']);
        $this->assertSame('ready', DB::table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->value('state'));
        $this->rejects(fn () => DB::table('accounts')->where('id', $f['account'])->update(['balance' => '101.00']));
        DB::rollBack();
        $this->assertSame('100.00', DB::table('accounts')->where('id', $f['account'])->value('balance'));
    }

    public function test_busy_earlier_dependency_timeout_cannot_commit_the_source_mutation(): void
    {
        $f = $this->fixture();
        $name = 'active_protection_busy_probe';
        config(['database.connections.'.$name => config('database.connections.mysql_testing')]);
        $this->probeConnections[] = $name;
        $probe = DB::connection($name);
        $connection = DB::connection();
        $this->assertNotSame($connection->getPdo(), $probe->getPdo());
        $timeout = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS timeout')->timeout;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 2');
        try {
            $probe->beginTransaction();
            $locked = $probe->table('operational_accounting_integrations')->where('user_id', $f['owner']->id)->lockForUpdate()->first();
            $this->assertNotNull($locked);
            $this->assertSame((string) $f['owner']->id, (string) $locked->user_id);
            $connection->beginTransaction();
            try {
                DB::table('accounts')->where('id', $f['account'])->update(['balance' => '101.00']);
                $this->fail('Source mutation bypassed the locked ACTIVE dependency.');
            } catch (QueryException $exception) {
                $this->assertSame('HY000', $exception->errorInfo[0], $exception->getMessage());
                $this->assertSame(1205, $exception->errorInfo[1], $exception->getMessage());
                $this->assertStringContainsString('Lock wait timeout exceeded', $exception->getMessage());
            }
            // A raw caller's later COMMIT cannot resurrect the failed UPDATE.
            $connection->commit();
            $this->assertSame('100.00', $connection->table('accounts')->where('id', $f['account'])->value('balance'));
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($probe->transactionLevel() > 0) {
                $probe->rollBack();
            }
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
        }
        $this->assertSame('100.00', DB::table('accounts')->where('id', $f['account'])->value('balance'));
    }
}
