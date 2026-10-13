<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithEvidenceProtectionTriggers;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalReconciliationEvidenceSchemaTest extends TestCase
{
    use InteractsWithEvidenceProtectionTriggers;
    use RefreshFinancialDatabase;

    private array $fixtureReviewIds = [];

    public function refreshDatabase(): void
    {
        // This suite tests 000009-000014 independently of the later trigger layer.
        // DDL occurs before fixtures and protections are restored after rollback.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $binding = require database_path('migrations/2026_10_10_000016_bind_operational_reconciliation_reviews.php');
        $binding->down();
        $protection = require database_path('migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php');
        $this->assertEvidenceEmpty();
        $this->assertProtectionTriggersInstalledExactly();
        $protection->down();
        $this->beginDatabaseTransaction();
        $this->beforeApplicationDestroyed(function () use ($protection, $binding): void {
            // Runs AFTER RefreshDatabase's rollback. Clean only this test's IDs
            // even if an attempted DDL statement implicitly committed fixtures.
            foreach (['attributions', 'journals', 'categories', 'accounts', 'charts'] as $suffix) {
                DB::table('operational_reconciliation_'.$suffix)->whereIn('review_id', $this->fixtureReviewIds)->delete();
            }
            foreach (array_reverse($this->fixtureReviewIds) as $id) {
                DB::table('operational_reconciliation_reviews')->where('id', $id)->delete();
            }
            $this->assertEvidenceEmpty();
            $protection->up();
            $this->assertProtectionTriggersInstalledExactly();
            $binding->up();
        });
    }

    private function assertEvidenceEmpty(): void
    {
        foreach (['reviews', 'charts', 'accounts', 'categories', 'journals', 'attributions'] as $suffix) {
            $this->assertDatabaseCount('operational_reconciliation_'.$suffix, 0);
        }
    }

    private function rejects(callable $operation, int $code = 3819): void
    {
        try {
            $operation();
            $this->fail('Invalid evidence foundation operation was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[1]);
        }
    }

    private function review(int $owner, array $overrides = []): int
    {
        $id = DB::table('operational_reconciliation_reviews')->insertGetId(array_replace([
            'user_id' => $owner, 'reference' => (string) Str::uuid(),
            'historical_transaction_strategy' => 'historical_backfill', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 0, 'scope_reviewed_at' => '2026-10-12 00:00:00',
            'created_at' => '2026-10-12 00:00:00',
        ], $overrides));
        $this->fixtureReviewIds[] = $id;

        return $id;
    }

    private function fixture(): array
    {
        $owner = User::factory()->create()->id;
        $review = $this->review($owner);
        $asset = DB::table('chart_of_accounts')->insertGetId([
            'user_id' => $owner, 'code' => '1000', 'name' => 'Asset', 'type' => 'asset',
        ]);
        $revenue = DB::table('chart_of_accounts')->insertGetId([
            'user_id' => $owner, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue',
        ]);
        foreach ([$asset => 'asset', $revenue => 'revenue'] as $chart => $type) {
            DB::table('operational_reconciliation_charts')->insert([
                'review_id' => $review, 'user_id' => $owner, 'chart_account_id' => $chart,
                'type_snapshot' => $type, 'cash_role_snapshot' => 'non_cash', 'is_active_snapshot' => 1,
            ]);
        }
        $accounts = [];
        for ($number = 1; $number <= 2; $number++) {
            $accounts[] = $account = DB::table('accounts')->insertGetId([
                'user_id' => $owner, 'name' => 'Account '.$number, 'type' => 'bank', 'balance' => '0.00', 'currency' => 'SAR',
            ]);
            DB::table('operational_reconciliation_accounts')->insert([
                'review_id' => $review, 'user_id' => $owner, 'account_id' => $account,
                'chart_account_id' => $asset, 'stored_balance_snapshot' => '-12.34', 'currency_snapshot' => 'SAR',
            ]);
        }
        [$account, $secondAccount] = $accounts;
        $category = DB::table('categories')->insertGetId(['user_id' => $owner, 'name' => 'Income', 'type' => 'income']);
        DB::table('operational_reconciliation_categories')->insert([
            'review_id' => $review, 'user_id' => $owner, 'category_id' => $category,
            'category_type_snapshot' => 'income', 'chart_account_id' => $revenue, 'cash_flow_activity' => 'operating',
        ]);
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner, 'entry_date' => '2026-10-12', 'currency' => 'SAR',
        ]);
        $line = DB::table('journal_lines')->insertGetId([
            'user_id' => $owner, 'journal_entry_id' => $journal, 'chart_account_id' => $asset,
            'line_number' => 1, 'debit' => '10000.00', 'credit' => '0.00',
        ]);
        DB::table('operational_reconciliation_journals')->insert([
            'review_id' => $review, 'user_id' => $owner, 'journal_entry_id' => $journal,
        ]);

        return compact('owner', 'review', 'asset', 'revenue', 'account', 'secondAccount', 'category', 'journal', 'line');
    }

    private function attribution(array $fixture, array $overrides = []): array
    {
        return array_replace([
            'review_id' => $fixture['review'], 'user_id' => $fixture['owner'],
            'journal_entry_id' => $fixture['journal'], 'journal_line_id' => $fixture['line'],
            'target_kind' => 'account', 'account_id' => $fixture['account'],
            'purpose' => 'existing_history', 'amount' => '1.00', 'rationale' => null,
        ], $overrides);
    }

    public function test_exact_columns_types_nullability_defaults_and_generated_key(): void
    {
        $id = ['bigint unsigned', false];
        $nullableId = ['bigint unsigned', true];
        $expected = [
            'reviews' => [
                'id' => $id, 'user_id' => $id, 'reference' => ['varchar(255)', false],
                'historical_transaction_strategy' => ['varchar(32)', false], 'accounting_currency' => ['char(3)', false],
                'transaction_high_water_id' => $id, 'scope_reviewed_at' => ['timestamp', false],
                'reviewed_by' => $nullableId, 'completed_at' => ['datetime(6)', true], 'supersedes_review_id' => $nullableId,
                'balance_only_disclosure_code' => ['varchar(32)', true], 'balance_only_disclosure_text' => ['text', true],
                'created_at' => ['datetime(6)', false],
            ],
            'charts' => [
                'review_id' => $id, 'user_id' => $id, 'chart_account_id' => $id,
                'type_snapshot' => ['varchar(16)', false], 'cash_role_snapshot' => ['varchar(16)', false],
                'is_active_snapshot' => ['tinyint(1)', false],
            ],
            'accounts' => [
                'review_id' => $id, 'user_id' => $id, 'account_id' => $id, 'chart_account_id' => $id,
                'stored_balance_snapshot' => ['decimal(15,2)', false], 'currency_snapshot' => ['char(3)', false], 'rationale' => ['text', true],
            ],
            'categories' => [
                'review_id' => $id, 'user_id' => $id, 'category_id' => $id,
                'category_type_snapshot' => ['varchar(16)', false], 'chart_account_id' => $id, 'cash_flow_activity' => ['varchar(16)', false],
            ],
            'journals' => ['review_id' => $id, 'user_id' => $id, 'journal_entry_id' => $id],
            'attributions' => [
                'id' => $id, 'review_id' => $id, 'user_id' => $id, 'journal_entry_id' => $id, 'journal_line_id' => $id,
                'target_kind' => ['varchar(16)', false], 'account_id' => $nullableId, 'purpose' => ['varchar(32)', true],
                'amount' => ['decimal(15,2)', false], 'rationale' => ['text', true], 'target_key' => ['varchar(37)', true],
            ],
        ];
        foreach ($expected as $suffix => $definitions) {
            $table = 'operational_reconciliation_'.$suffix;
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            $this->assertSame(array_keys($definitions), $columns->keys()->all(), $table);
            foreach ($definitions as $name => [$type, $nullable]) {
                $this->assertSame($type, strtolower($columns[$name]['type']), $table.'.'.$name);
                $this->assertSame($nullable, $columns[$name]['nullable'], $table.'.'.$name);
                if (! in_array($name, ['id', 'created_at', 'target_key'], true)) {
                    $this->assertNull($columns[$name]['default'], $table.'.'.$name);
                }
            }
            $metadata = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->first();
            $this->assertSame('InnoDB', $metadata->ENGINE);
            $this->assertSame('utf8mb4_unicode_ci', $metadata->TABLE_COLLATION);
        }
        $created = collect(Schema::getColumns('operational_reconciliation_reviews'))->firstWhere('name', 'created_at');
        $this->assertSame('current_timestamp(6)', strtolower($created['default']));
        $key = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'operational_reconciliation_attributions')->where('COLUMN_NAME', 'target_key')->first();
        $this->assertSame('ascii', $key->CHARACTER_SET_NAME);
        $this->assertSame('ascii_bin', $key->COLLATION_NAME);
        $this->assertStringContainsString('STORED GENERATED', $key->EXTRA);
        $this->assertNotEmpty($key->GENERATION_EXPRESSION);
    }

    public function test_exact_primary_unique_and_restrictive_foreign_keys_have_supporting_indexes(): void
    {
        $primary = [
            'reviews' => ['id'], 'charts' => ['review_id', 'chart_account_id'],
            'accounts' => ['review_id', 'account_id'], 'categories' => ['review_id', 'category_id'],
            'journals' => ['review_id', 'journal_entry_id'], 'attributions' => ['id'],
        ];
        $unique = [
            'reviews' => [['id', 'user_id'], ['user_id', 'reference']],
            'charts' => [['review_id', 'user_id', 'chart_account_id']],
            'accounts' => [['review_id', 'user_id', 'account_id']],
            'journals' => [['review_id', 'user_id', 'journal_entry_id']],
            'attributions' => [['review_id', 'journal_line_id', 'target_key']],
        ];
        $foreignCount = ['reviews' => 3, 'charts' => 2, 'accounts' => 3, 'categories' => 3, 'journals' => 2, 'attributions' => 4];
        foreach ($primary as $suffix => $key) {
            $table = 'operational_reconciliation_'.$suffix;
            $indexes = collect(Schema::getIndexes($table));
            $this->assertTrue($indexes->contains(fn ($index) => $index['primary'] && $index['columns'] === $key));
            foreach ($unique[$suffix] ?? [] as $columns) {
                $this->assertTrue($indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns));
            }
            $foreign = Schema::getForeignKeys($table);
            $this->assertCount($foreignCount[$suffix], $foreign);
            foreach ($foreign as $constraint) {
                $this->assertSame('restrict', strtolower($constraint['on_delete']));
                $this->assertSame('restrict', strtolower($constraint['on_update']));
                $this->assertTrue($indexes->contains(fn ($index) => array_slice($index['columns'], 0, count($constraint['columns'])) === $constraint['columns']));
                $this->assertNotContains($constraint['foreign_table'], [
                    'operational_account_mappings', 'operational_category_mappings',
                    'operational_accounting_account_scope', 'operational_accounting_category_scope',
                ]);
            }
        }
    }

    public function test_header_checks_and_no_automatic_reviewed_metadata(): void
    {
        $owner = User::factory()->create()->id;
        foreach ([
            ['reference' => 'AAAAAAAA-AAAA-AAAA-AAAA-AAAAAAAAAAAA'], ['reference' => 'not-a-uuid'],
            ['reference' => (string) Str::uuid().' '], ['historical_transaction_strategy' => 'HISTORICAL_BACKFILL'],
            ['historical_transaction_strategy' => 'unknown'], ['accounting_currency' => 'sar'],
            ['accounting_currency' => 'S4R'], ['accounting_currency' => 'SA'], ['accounting_currency' => 'ＳＡＲ'],
            ['reviewed_by' => $owner], ['completed_at' => '2026-10-13 00:00:00'],
            ['reviewed_by' => $owner, 'completed_at' => '2026-10-11 23:59:59.999999'],
            ['balance_only_disclosure_code' => 'balance_only_legacy_history_v1'],
            ['balance_only_disclosure_text' => 'Unexpected disclosure'],
            ['historical_transaction_strategy' => 'balance_only'],
            ['historical_transaction_strategy' => 'balance_only', 'balance_only_disclosure_code' => 'other', 'balance_only_disclosure_text' => 'Disclosure'],
            ['historical_transaction_strategy' => 'balance_only', 'balance_only_disclosure_code' => 'balance_only_legacy_history_v1', 'balance_only_disclosure_text' => " \t\n"],
        ] as $invalid) {
            $this->rejects(fn () => $this->review($owner, $invalid));
        }
        $id = $this->review($owner);
        $row = DB::table('operational_reconciliation_reviews')->find($id);
        $this->assertNull($row->reviewed_by);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->supersedes_review_id);
        $this->assertNull($row->balance_only_disclosure_code);
        $this->assertNull($row->balance_only_disclosure_text);
        $this->assertSame('historical_backfill', $row->historical_transaction_strategy);
        $this->rejects(fn () => $this->review($owner, ['scope_reviewed_at' => null]), 1048);
        $this->rejects(fn () => $this->review($owner, ['reference' => null]), 1048);
        $this->review($owner, [
            'historical_transaction_strategy' => 'balance_only',
            'balance_only_disclosure_code' => 'balance_only_legacy_history_v1',
            'balance_only_disclosure_text' => 'Legacy income statement is incomplete.',
        ]);
        $this->review($owner, ['reviewed_by' => $owner, 'completed_at' => '2026-10-13 00:00:00.123456']);
        $this->rejects(fn () => $this->review($owner, ['reference' => $row->reference]), 1062);
    }

    public function test_chart_category_and_account_value_checks_and_exact_decimals(): void
    {
        $f = $this->fixture();
        $charts = DB::table('operational_reconciliation_charts')->where('review_id', $f['review'])->where('chart_account_id', $f['asset']);
        foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $type) {
            $charts->update(['type_snapshot' => $type, 'cash_role_snapshot' => 'non_cash']);
            $this->assertSame($type, $charts->value('type_snapshot'));
        }
        $charts->update(['type_snapshot' => 'asset']);
        foreach (['non_cash', 'cash', 'cash_equivalent'] as $role) {
            $charts->update(['cash_role_snapshot' => $role]);
            $this->assertSame($role, $charts->value('cash_role_snapshot'));
        }
        foreach ([['type_snapshot' => 'ASSET'], ['cash_role_snapshot' => 'unknown'],
            ['type_snapshot' => 'expense', 'cash_role_snapshot' => 'cash'], ['is_active_snapshot' => 2]] as $invalid) {
            $this->rejects(fn () => $charts->update($invalid));
        }
        $charts->update(['is_active_snapshot' => 0]);
        $this->assertSame(0, $charts->value('is_active_snapshot'));
        $categories = DB::table('operational_reconciliation_categories')->where('review_id', $f['review']);
        foreach (['income', 'expense'] as $type) {
            $categories->update(['category_type_snapshot' => $type]);
            $this->assertSame($type, $categories->value('category_type_snapshot'));
        }
        foreach (['operating', 'investing', 'financing'] as $activity) {
            $categories->update(['cash_flow_activity' => $activity]);
            $this->assertSame($activity, $categories->value('cash_flow_activity'));
        }
        $this->rejects(fn () => $categories->update(['category_type_snapshot' => 'transfer']));
        $this->rejects(fn () => $categories->update(['cash_flow_activity' => 'OPERATING']));
        $accounts = DB::table('operational_reconciliation_accounts')->where('review_id', $f['review'])->where('account_id', $f['account']);
        foreach (['-12.34', '-9999999999999.99', '9999999999999.99'] as $balance) {
            $accounts->update(['stored_balance_snapshot' => $balance]);
            $this->assertSame($balance, $accounts->value('stored_balance_snapshot'));
        }
        $this->rejects(fn () => $accounts->update(['stored_balance_snapshot' => '10000000000000.00']), 1264);
        $this->rejects(fn () => $accounts->update(['currency_snapshot' => 'sar']));
        $this->rejects(fn () => $accounts->update(['rationale' => " \t\n"]));
        $accounts->update(['rationale' => 'Explicit review']);
        $this->assertSame('Explicit review', $accounts->value('rationale'));
    }

    public function test_cross_owner_and_missing_parent_foreign_keys_reject(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $missing = User::factory()->create()->id;
        User::findOrFail($missing)->delete();
        $this->rejects(fn () => $this->review($missing), 1452);
        $this->rejects(fn () => $this->review($a['owner'], ['reviewed_by' => $missing, 'completed_at' => '2026-10-13 00:00:00']), 1452);
        $this->rejects(fn () => $this->review($a['owner'], ['supersedes_review_id' => $b['review']]), 1452);
        $this->rejects(fn () => $this->review($a['owner'], ['supersedes_review_id' => $missing + 1000000]), 1452);
        $cases = [
            ['charts', ['chart_account_id' => $b['asset']]], ['charts', ['review_id' => $b['review']]],
            ['accounts', ['account_id' => $b['account']]], ['accounts', ['account_id' => $b['secondAccount'], 'chart_account_id' => $b['asset']]],
            ['categories', ['category_id' => $b['category']]], ['categories', ['category_id' => $b['category'], 'chart_account_id' => $b['revenue']]],
            ['journals', ['journal_entry_id' => $b['journal']]],
        ];
        foreach ($cases as [$suffix, $changes]) {
            $row = (array) DB::table('operational_reconciliation_'.$suffix)->where('review_id', $a['review'])->first();
            $this->rejects(fn () => DB::table('operational_reconciliation_'.$suffix)->insert(array_replace($row, $changes)), 1452);
        }
        foreach (['charts' => 'chart_account_id', 'accounts' => 'account_id', 'categories' => 'category_id', 'journals' => 'journal_entry_id'] as $suffix => $column) {
            $row = (array) DB::table('operational_reconciliation_'.$suffix)->where('review_id', $a['review'])->first();
            $this->rejects(fn () => DB::table('operational_reconciliation_'.$suffix)->insert(array_replace($row, [$column => $missing + 1000000])), 1452);
        }
        foreach ([['account_id' => $b['account']], ['journal_line_id' => $b['line']],
            ['journal_entry_id' => $b['journal']], ['review_id' => $b['review']],
            ['journal_line_id' => $missing + 1000000]] as $changes) {
            $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert($this->attribution($a, $changes)), 1452);
        }
        $this->review($a['owner'], ['supersedes_review_id' => $a['review']]);
        $this->assertDatabaseCount('operational_reconciliation_reviews', 3);
    }

    public function test_referenced_parent_deletion_and_key_update_are_restricted(): void
    {
        $f = $this->fixture();
        DB::table('operational_reconciliation_attributions')->insert($this->attribution($f));
        foreach ([
            ['users', 'id', $f['owner']], ['accounts', 'id', $f['account']],
            ['categories', 'id', $f['category']], ['chart_of_accounts', 'id', $f['asset']],
            ['journal_entries', 'id', $f['journal']], ['journal_lines', 'id', $f['line']],
            ['operational_reconciliation_reviews', 'id', $f['review']],
            ['operational_reconciliation_charts', 'chart_account_id', $f['asset']],
            ['operational_reconciliation_accounts', 'account_id', $f['account']],
            ['operational_reconciliation_journals', 'journal_entry_id', $f['journal']],
        ] as [$table, $key, $value]) {
            $this->rejects(fn () => DB::table($table)->where($key, $value)->delete(), 1451);
            $this->rejects(fn () => DB::table($table)->where($key, $value)->update([$key => $value + 1000000]), 1451);
        }
    }

    public function test_attribution_null_combinations_and_positive_amount_checks(): void
    {
        $f = $this->fixture();
        foreach ([
            ['target_kind' => 'residual'], ['target_kind' => 'ACCOUNT'], ['account_id' => null], ['account_id' => 0],
            ['purpose' => null], ['purpose' => 'historical_transaction'], ['purpose' => 'OPENING_BALANCE'],
            ['rationale' => " \t\n"], ['amount' => '0.00'], ['amount' => '-0.01'],
            ['target_kind' => 'excluded'],
            ['target_kind' => 'excluded', 'account_id' => null],
            ['target_kind' => 'excluded', 'account_id' => null, 'purpose' => null],
            ['target_kind' => 'excluded', 'account_id' => null, 'purpose' => null, 'rationale' => '  '],
            ['target_kind' => 'excluded', 'purpose' => null, 'rationale' => 'Excluded'],
        ] as $changes) {
            $this->rejects(fn () => DB::table('operational_reconciliation_attributions')->insert($this->attribution($f, $changes)));
        }
        DB::table('operational_reconciliation_attributions')->insert($this->attribution($f, ['amount' => '0.01']));
        $this->assertSame('0.01', DB::table('operational_reconciliation_attributions')->value('amount'));
    }

    public function test_generated_keys_allow_two_purposes_and_shared_targets_but_reject_duplicates(): void
    {
        $f = $this->fixture();
        $table = DB::table('operational_reconciliation_attributions');
        $history = $this->attribution($f);
        $table->insert($history);
        $this->rejects(fn () => $table->insert($history), 1062);
        $opening = $this->attribution($f, ['purpose' => 'opening_balance', 'rationale' => 'Reviewed opening basis']);
        $table->insert($opening);
        $this->rejects(fn () => $table->insert($opening), 1062);
        $table->insert($this->attribution($f, ['account_id' => $f['secondAccount']]));
        $excluded = $this->attribution($f, ['target_kind' => 'excluded', 'account_id' => null, 'purpose' => null, 'rationale' => 'Reviewed nonparticipant history']);
        $table->insert($excluded);
        $this->rejects(fn () => $table->insert($excluded), 1062);
        $this->assertSame([
            $f['account'].':existing_history', $f['account'].':opening_balance',
            $f['secondAccount'].':existing_history', 'excluded',
        ], $table->orderBy('id')->pluck('target_key')->all());
        $this->assertDatabaseCount('operational_account_mappings', 0);
        $this->assertDatabaseCount('operational_category_mappings', 0);
        $this->assertDatabaseCount('operational_accounting_integrations', 0);
    }

    public function test_populated_evidence_rollback_refuses_without_ddl_or_data_loss(): void
    {
        $f = $this->fixture();
        DB::table('operational_reconciliation_attributions')->insert($this->attribution($f));
        foreach (range(9, 14) as $number) {
            $paths = glob(database_path(sprintf('migrations/2026_10_10_%06d_*.php', $number)));
            $this->assertCount(1, $paths);
            $migration = require $paths[0];
            try {
                $migration->down();
                $this->fail('Populated evidence was dropped.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Refusing to drop populated evidence table', $exception->getMessage());
            }
        }
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $f['review']]);
        $this->assertDatabaseCount('operational_reconciliation_attributions', 1);
    }
}
