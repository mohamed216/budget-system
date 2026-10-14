<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithActiveProtectionTriggers;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalAccountingSchemaTest extends TestCase
{
    use InteractsWithActiveProtectionTriggers;
    use RefreshFinancialDatabase;

    public function refreshDatabase(): void
    {
        // Original Slice 1 checks are tested independently of 000016's stronger
        // completed-review requirement; all fixtures roll back before restoration.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $active = $this->activeProtectionMigration();
        $active->down();
        $binding = require database_path('migrations/2026_10_10_000016_bind_operational_reconciliation_reviews.php');
        $binding->down();
        $this->beginDatabaseTransaction();
        $this->beforeApplicationDestroyed(function () use ($binding, $active): void {
            $binding->up();
            $active->up();
        });
    }

    private function rejects(callable $operation, array $codes): void
    {
        try {
            $operation();
            $this->fail('MySQL accepted an invalid Phase 3 schema operation.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[1], $codes);
        }
    }

    private function chart(int $owner, string $code, string $type): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'user_id' => $owner, 'code' => $code, 'name' => $code, 'type' => $type,
        ]);
    }

    private function legacy(int $owner): array
    {
        $account = DB::table('accounts')->insertGetId([
            'user_id' => $owner, 'name' => 'Operational account', 'type' => 'bank',
            'balance' => '100.00', 'currency' => config('accounting.currency'),
        ]);
        $category = DB::table('categories')->insertGetId([
            'user_id' => $owner, 'name' => 'Income', 'type' => 'income',
        ]);
        $transaction = DB::table('transactions')->insertGetId([
            'user_id' => $owner, 'account_id' => $account, 'category_id' => $category,
            'amount' => '1.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);

        return [$account, $category, $transaction];
    }

    private function postedJournal(int $owner, array $lines = [], string $date = '2026-10-06', ?string $currency = null): int
    {
        $journal = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner, 'entry_date' => $date,
            'currency' => $currency ?? config('accounting.currency'),
        ]);
        foreach ($lines as $number => [$chart, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'user_id' => $owner, 'journal_entry_id' => $journal, 'chart_account_id' => $chart,
                'line_number' => $number + 1, 'debit' => $debit, 'credit' => $credit,
            ]);
        }
        DB::table('journal_entries')->where('id', $journal)->update([
            'status' => 'posted', 'posted_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $journal;
    }

    private function operationalJournal(int $owner, int $accountChart, int $categoryChart, string $type, string $amount = '1.00', array $extraLines = [], string $date = '2026-10-06', ?string $currency = null): int
    {
        $lines = $type === 'income'
            ? [[$accountChart, $amount, '0.00'], [$categoryChart, '0.00', $amount]]
            : [[$categoryChart, $amount, '0.00'], [$accountChart, '0.00', $amount]];

        return $this->postedJournal($owner, array_merge($lines, $extraLines), $date, $currency);
    }

    private function mappingRows(int $owner, int $account, int $category, int $asset, int $revenue): void
    {
        DB::table('operational_account_mappings')->insert([
            'account_id' => $account, 'user_id' => $owner, 'chart_account_id' => $asset,
        ]);
        DB::table('operational_category_mappings')->insert([
            'category_id' => $category, 'user_id' => $owner, 'chart_account_id' => $revenue,
            'cash_flow_activity' => 'operating', 'reviewed_by' => $owner,
            'reviewed_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function test_columns_types_nullability_keys_and_restrictive_owner_foreign_keys(): void
    {
        $columns = [
            'operational_account_mappings' => [
                'account_id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false],
                'chart_account_id' => ['bigint unsigned', false],
                'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            ],
            'operational_category_mappings' => [
                'category_id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false],
                'chart_account_id' => ['bigint unsigned', false], 'cash_flow_activity' => ['varchar(16)', true],
                'reviewed_by' => ['bigint unsigned', true], 'reviewed_at' => ['datetime(6)', true],
                'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            ],
            'operational_transaction_journals' => [
                'transaction_id' => ['bigint unsigned', false], 'journal_entry_id' => ['bigint unsigned', false],
                'user_id' => ['bigint unsigned', false], 'account_id' => ['bigint unsigned', false],
                'category_id' => ['bigint unsigned', false], 'created_at' => ['timestamp', true],
            ],
            'operational_accounting_integrations' => [
                'user_id' => ['bigint unsigned', false], 'state' => ['varchar(16)', false],
                'accounting_currency' => ['char(3)', true], 'historical_transaction_strategy' => ['varchar(32)', true],
                'transaction_high_water_id' => ['bigint unsigned', true],
                'reconciliation_reference' => ['varchar(255)', true], 'reconciled_at' => ['datetime(6)', true],
                'scope_reviewed_at' => ['timestamp', true],
                'activated_at' => ['datetime(6)', true], 'created_at' => ['timestamp', true],
                'updated_at' => ['timestamp', true],
            ],
            'operational_accounting_account_scope' => [
                'account_id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false],
                'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            ],
            'operational_accounting_category_scope' => [
                'category_id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false],
                'created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true],
            ],
        ];
        foreach ($columns as $table => $expected) {
            $actual = collect(Schema::getColumns($table))->keyBy('name');
            $this->assertSame(array_keys($expected), $actual->keys()->all(), $table);
            foreach ($expected as $name => $typeAndNullable) {
                $this->assertSame($typeAndNullable, [$actual[$name]['type'], $actual[$name]['nullable']], "{$table}.{$name}");
            }
            $engine = DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::connection()->getDatabaseName(), $table]);
            $this->assertSame('InnoDB', $engine->ENGINE);
        }
        foreach (['accounts', 'categories', 'transactions'] as $table) {
            $this->assertTrue(collect(Schema::getColumns($table))->firstWhere('name', 'user_id')['nullable']);
        }

        $indexes = [
            'accounts' => ['id', 'user_id'], 'categories' => ['id', 'user_id'],
            'transactions' => ['id', 'user_id'], 'journal_entries' => ['id', 'user_id'],
            'chart_of_accounts' => ['id', 'user_id'],
            'operational_account_mappings' => ['account_id', 'user_id'],
            'operational_category_mappings' => ['category_id', 'user_id'],
            'operational_accounting_account_scope' => ['account_id', 'user_id'],
            'operational_accounting_category_scope' => ['category_id', 'user_id'],
        ];
        foreach ($indexes as $table => $key) {
            $matches = collect(Schema::getIndexes($table))->filter(fn (array $index) => $index['unique'] && $index['columns'] === $key);
            $this->assertCount(1, $matches, "{$table} must have one referenceable owner key without a duplicate equivalent index.");
        }
        $this->assertSame(['transaction_id'], collect(Schema::getIndexes('operational_transaction_journals'))->firstWhere('name', 'primary')['columns']);
        $this->assertSame(['journal_entry_id'], collect(Schema::getIndexes('operational_transaction_journals'))->firstWhere('name', 'otj_journal_unique')['columns']);
        $this->assertSame(['user_id'], collect(Schema::getIndexes('operational_accounting_integrations'))->firstWhere('name', 'primary')['columns']);
        $this->assertSame(['account_id'], collect(Schema::getIndexes('operational_accounting_account_scope'))->firstWhere('name', 'primary')['columns']);
        $this->assertSame(['category_id'], collect(Schema::getIndexes('operational_accounting_category_scope'))->firstWhere('name', 'primary')['columns']);

        $foreignKeys = [
            'operational_account_mappings' => [
                'oam_account_owner_fk' => [['account_id', 'user_id'], 'accounts', ['id', 'user_id']],
                'oam_chart_owner_fk' => [['chart_account_id', 'user_id'], 'chart_of_accounts', ['id', 'user_id']],
            ],
            'operational_category_mappings' => [
                'ocm_category_owner_fk' => [['category_id', 'user_id'], 'categories', ['id', 'user_id']],
                'ocm_chart_owner_fk' => [['chart_account_id', 'user_id'], 'chart_of_accounts', ['id', 'user_id']],
                'ocm_reviewer_fk' => [['reviewed_by'], 'users', ['id']],
            ],
            'operational_transaction_journals' => [
                'otj_transaction_owner_fk' => [['transaction_id', 'user_id'], 'transactions', ['id', 'user_id']],
                'otj_journal_owner_fk' => [['journal_entry_id', 'user_id'], 'journal_entries', ['id', 'user_id']],
                'otj_account_owner_fk' => [['account_id', 'user_id'], 'operational_account_mappings', ['account_id', 'user_id']],
                'otj_category_owner_fk' => [['category_id', 'user_id'], 'operational_category_mappings', ['category_id', 'user_id']],
            ],
            'operational_accounting_integrations' => [
                'oai_owner_fk' => [['user_id'], 'users', ['id']],
            ],
            'operational_accounting_account_scope' => [
                'oaas_account_owner_fk' => [['account_id', 'user_id'], 'accounts', ['id', 'user_id']],
                'oaas_owner_fk' => [['user_id'], 'users', ['id']],
            ],
            'operational_accounting_category_scope' => [
                'oacs_category_owner_fk' => [['category_id', 'user_id'], 'categories', ['id', 'user_id']],
                'oacs_owner_fk' => [['user_id'], 'users', ['id']],
            ],
        ];
        foreach ($foreignKeys as $table => $expected) {
            $actual = collect(Schema::getForeignKeys($table))->keyBy('name');
            $this->assertCount(count($expected), $actual, $table);
            foreach ($expected as $name => [$columns, $parent, $parentColumns]) {
                $key = $actual[$name];
                $this->assertSame([$columns, $parent, $parentColumns], [$key['columns'], $key['foreign_table'], $key['foreign_columns']]);
                $this->assertSame('restrict', $key['on_delete']);
                $this->assertSame('restrict', $key['on_update']);
            }
        }
    }

    public function test_activity_state_and_review_constraints(): void
    {
        $owner = User::factory()->create();
        [$account, $category] = $this->legacy($owner->id);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        DB::table('operational_account_mappings')->insert([
            'account_id' => $account, 'user_id' => $owner->id, 'chart_account_id' => $asset,
        ]);
        $base = ['category_id' => $category, 'user_id' => $owner->id, 'chart_account_id' => $revenue];
        $this->rejects(fn () => DB::table('operational_category_mappings')->insert($base + [
            'cash_flow_activity' => 'OPERATING', 'reviewed_by' => $owner->id, 'reviewed_at' => now(),
        ]), [3819]);
        $this->rejects(fn () => DB::table('operational_category_mappings')->insert($base + [
            'cash_flow_activity' => 'operating',
        ]), [3819]);
        DB::table('operational_category_mappings')->insert($base);
        $this->rejects(fn () => DB::table('operational_accounting_integrations')->insert([
            'user_id' => $owner->id, 'state' => 'ACTIVE',
        ]), [3819]);
        DB::table('operational_accounting_integrations')->insert(['user_id' => $owner->id]);
        $this->assertSame('legacy', DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->value('state'));
        foreach (['reconciling', 'ready', 'active'] as $state) {
            if ($state === 'ready') {
                $this->rejects(fn () => DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update(['state' => 'ready']), [3819]);
                DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update([
                    'historical_transaction_strategy' => 'historical_backfill', 'transaction_high_water_id' => 1,
                    'reconciliation_reference' => 'reviewed', 'reconciled_at' => now(), 'scope_reviewed_at' => now(),
                ]);
            }
            if ($state === 'active') {
                $this->rejects(fn () => DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update(['state' => 'active']), [3819]);
                DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update([
                    'accounting_currency' => config('accounting.currency'), 'activated_at' => now(),
                ]);
            }
            DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update(['state' => $state]);
        }
    }

    public function test_historical_strategy_and_ready_active_metadata_constraints(): void
    {
        $owner = User::factory()->create();
        $integrations = DB::table('operational_accounting_integrations')->where('user_id', $owner->id);
        foreach (['legacy', 'reconciling'] as $state) {
            DB::table('operational_accounting_integrations')->insert([
                'user_id' => $owner->id, 'state' => $state,
            ]);
            $this->assertNull($integrations->value('historical_transaction_strategy'));
            $this->assertNull($integrations->value('scope_reviewed_at'));
            foreach (['historical_backfill', 'balance_only'] as $strategy) {
                $integrations->update(['historical_transaction_strategy' => $strategy]);
                $this->assertSame($strategy, $integrations->value('historical_transaction_strategy'));
            }
            foreach (['invalid', '', 'HISTORICAL_BACKFILL', 'BALANCE_ONLY', 'balance_only '] as $strategy) {
                $this->rejects(fn () => $integrations->update(['historical_transaction_strategy' => $strategy]), [3819]);
            }
            $integrations->update(['historical_transaction_strategy' => null]);
            $this->assertNull($integrations->value('historical_transaction_strategy'));
            $integrations->delete();
        }

        foreach (['historical_backfill', 'balance_only'] as $strategy) {
            foreach (['ready', 'active'] as $state) {
                $metadata = [
                    'user_id' => $owner->id, 'state' => $state,
                    'historical_transaction_strategy' => $strategy, 'transaction_high_water_id' => 0,
                    'reconciliation_reference' => 'reviewed-'.$strategy, 'reconciled_at' => now(),
                    'scope_reviewed_at' => now(),
                    'accounting_currency' => $state === 'active' ? config('accounting.currency') : null,
                    'activated_at' => $state === 'active' ? now() : null,
                ];
                $required = ['historical_transaction_strategy', 'transaction_high_water_id', 'reconciliation_reference', 'reconciled_at', 'scope_reviewed_at'];
                if ($state === 'active') {
                    $required = array_merge($required, ['accounting_currency', 'activated_at']);
                }
                foreach ($required as $field) {
                    $this->rejects(fn () => DB::table('operational_accounting_integrations')->insert(array_replace($metadata, [$field => null])), [3819]);
                }
                DB::table('operational_accounting_integrations')->insert($metadata);
                $this->assertSame($strategy, $integrations->value('historical_transaction_strategy'));
                $this->assertSame($state, $integrations->value('state'));
                $this->assertNotNull($integrations->value('scope_reviewed_at'));
                // Explicit review represents an empty scope without inventing rows.
                $this->assertDatabaseCount('operational_accounting_account_scope', 0);
                $this->assertDatabaseCount('operational_accounting_category_scope', 0);
                foreach ($required as $field) {
                    $this->rejects(fn () => $integrations->update([$field => null]), [3819]);
                }
                $integrations->delete();
            }
        }
    }

    public function test_scope_is_owner_safe_and_allows_selected_unmapped_records(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        // No transactions or mappings: scope alone must protect these parents.
        $account = DB::table('accounts')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Selected unmapped account', 'type' => 'bank',
            'balance' => '0.00', 'currency' => config('accounting.currency'),
        ]);
        $category = DB::table('categories')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Selected unmapped category', 'type' => 'income',
        ]);
        foreach ([
            ['operational_accounting_account_scope', 'account_id', 'accounts', 'operational_account_mappings', $account],
            ['operational_accounting_category_scope', 'category_id', 'categories', 'operational_category_mappings', $category],
        ] as [$scope, $key, $parent, $mapping, $id]) {
            $this->rejects(fn () => DB::table($scope)->insert([$key => $id, 'user_id' => $other->id]), [1452]);
            $this->rejects(fn () => DB::table($scope)->insert([$key => 999999, 'user_id' => $owner->id]), [1452]);
            $this->rejects(fn () => DB::table($scope)->insert([$key => $id, 'user_id' => 999999]), [1452]);
            DB::table($scope)->insert([$key => $id, 'user_id' => $owner->id]);
            $this->assertDatabaseHas($scope, [$key => $id, 'user_id' => $owner->id]);
            $this->assertDatabaseMissing($mapping, [$key => $id]);
            $this->rejects(fn () => DB::table($scope)->insert([$key => $id, 'user_id' => $owner->id]), [1062]);
            $this->rejects(fn () => DB::table($parent)->where('id', $id)->delete(), [1451]);
            $this->rejects(fn () => DB::table($parent)->where('id', $id)->update(['user_id' => $other->id]), [1451]);
            $this->rejects(fn () => DB::table($parent)->where('id', $id)->update(['id' => 999999]), [1451]);
            $this->rejects(fn () => DB::table($scope)->where($key, $id)->update(['user_id' => $other->id]), [1452]);
        }
        $this->rejects(fn () => DB::table('users')->where('id', $owner->id)->delete(), [1451]);
        $this->assertDatabaseCount('operational_accounting_integrations', 0);
    }

    public function test_composite_foreign_keys_reject_cross_owner_rows_and_restrict_parent_changes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$account, $category] = $this->legacy($owner->id);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $foreignAsset = $this->chart($other->id, 'OTHER-ASSET', 'asset');
        $foreignRevenue = $this->chart($other->id, 'OTHER-REVENUE', 'revenue');
        $this->rejects(fn () => DB::table('operational_account_mappings')->insert([
            'account_id' => $account, 'user_id' => $owner->id, 'chart_account_id' => $foreignAsset,
        ]), [1452]);
        $this->rejects(fn () => DB::table('operational_category_mappings')->insert([
            'category_id' => $category, 'user_id' => $owner->id, 'chart_account_id' => $foreignRevenue,
        ]), [1452]);
        $this->mappingRows($owner->id, $account, $category, $asset, $revenue);
        $this->rejects(fn () => DB::table('operational_account_mappings')->insert([
            'account_id' => $account, 'user_id' => $owner->id, 'chart_account_id' => $asset,
        ]), [1062]);
        $this->rejects(fn () => DB::table('operational_category_mappings')->insert([
            'category_id' => $category, 'user_id' => $owner->id, 'chart_account_id' => $revenue,
        ]), [1062]);
        $this->rejects(fn () => DB::table('accounts')->where('id', $account)->update(['user_id' => $other->id]), [1451]);
        $this->rejects(fn () => DB::table('categories')->where('id', $category)->delete(), [1451]);
        $this->rejects(fn () => DB::table('chart_of_accounts')->where('id', $asset)->delete(), [1451]);
    }

    public function test_link_requires_matching_posted_owned_records_and_is_immutable(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$account, $category, $transaction] = $this->legacy($owner->id);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $this->mappingRows($owner->id, $account, $category, $asset, $revenue);
        $draft = DB::table('journal_entries')->insertGetId([
            'user_id' => $owner->id, 'entry_date' => '2026-10-06', 'currency' => config('accounting.currency'),
        ]);
        $base = [
            'transaction_id' => $transaction, 'journal_entry_id' => $draft, 'user_id' => $owner->id,
            'account_id' => $account, 'category_id' => $category,
        ];
        $this->rejects(fn () => DB::table('operational_transaction_journals')->insert($base), [1644]);
        $foreignJournal = $this->postedJournal($other->id);
        $this->rejects(fn () => DB::table('operational_transaction_journals')->insert(array_replace($base, [
            'journal_entry_id' => $foreignJournal,
        ])), [1452, 1644]);
        $posted = $this->operationalJournal($owner->id, $asset, $revenue, 'income');
        $this->rejects(fn () => DB::table('operational_transaction_journals')->insert(array_replace($base, [
            'journal_entry_id' => $posted, 'account_id' => 999999,
        ])), [1644]);
        DB::table('operational_transaction_journals')->insert(array_replace($base, ['journal_entry_id' => $posted]));
        $secondTransaction = DB::table('transactions')->insertGetId([
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $category,
            'amount' => '1.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);
        $this->rejects(fn () => DB::table('operational_transaction_journals')->insert(array_replace($base, [
            'transaction_id' => $secondTransaction, 'journal_entry_id' => $posted,
        ])), [1062]);
        $this->rejects(fn () => DB::table('operational_transaction_journals')->insert(array_replace($base, [
            'journal_entry_id' => $draft,
        ])), [1062, 1644]);
        $this->rejects(fn () => DB::table('operational_transaction_journals')->where('transaction_id', $transaction)->update([
            'account_id' => $account,
        ]), [1644]);
        $this->rejects(fn () => DB::table('operational_transaction_journals')->where('transaction_id', $transaction)->delete(), [1644]);
        $this->rejects(fn () => DB::table('transactions')->where('id', $transaction)->delete(), [1451]);
        $this->assertDatabaseCount('operational_transaction_journals', 1);
    }

    public function test_link_rejects_unrelated_same_owner_posted_journals(): void
    {
        $owner = User::factory()->create();
        [$account, $category, $transaction] = $this->legacy($owner->id);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $other = $this->chart($owner->id, 'OTHER', 'asset');
        $this->mappingRows($owner->id, $account, $category, $asset, $revenue);
        $base = [
            'transaction_id' => $transaction, 'user_id' => $owner->id,
            'account_id' => $account, 'category_id' => $category,
        ];
        $journals = [
            $this->postedJournal($owner->id),
            $this->operationalJournal($owner->id, $asset, $revenue, 'income', '1.00', [], '2026-10-07'),
            $this->operationalJournal($owner->id, $asset, $revenue, 'income', '1.00', [], '2026-10-06', 'USD'),
            $this->operationalJournal($owner->id, $other, $revenue, 'income'),
            $this->postedJournal($owner->id, [[$revenue, '1.00', '0.00'], [$asset, '0.00', '1.00']]),
            $this->operationalJournal($owner->id, $asset, $revenue, 'income', '2.00'),
            $this->operationalJournal($owner->id, $asset, $revenue, 'income', '1.00', [[$other, '0.00', '1.00']]),
        ];
        foreach ($journals as $journal) {
            $this->rejects(fn () => DB::table('operational_transaction_journals')->insert($base + ['journal_entry_id' => $journal]), [1644]);
        }
        $this->assertDatabaseCount('operational_transaction_journals', 0);
    }

    public function test_valid_income_and_expense_operational_links_succeed(): void
    {
        $owner = User::factory()->create();
        [$account, $incomeCategory, $incomeTransaction] = $this->legacy($owner->id);
        $expenseCategory = DB::table('categories')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Expense', 'type' => 'expense',
        ]);
        $expenseTransaction = DB::table('transactions')->insertGetId([
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $expenseCategory,
            'amount' => '2.00', 'type' => 'expense', 'date' => '2026-10-06',
        ]);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $expense = $this->chart($owner->id, 'EXPENSE', 'expense');
        $this->mappingRows($owner->id, $account, $incomeCategory, $asset, $revenue);
        DB::table('operational_category_mappings')->insert([
            'category_id' => $expenseCategory, 'user_id' => $owner->id, 'chart_account_id' => $expense,
            'cash_flow_activity' => 'operating', 'reviewed_by' => $owner->id,
            'reviewed_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('operational_transaction_journals')->insert([
            'transaction_id' => $incomeTransaction,
            'journal_entry_id' => $this->operationalJournal($owner->id, $asset, $revenue, 'income'),
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $incomeCategory,
        ]);
        DB::table('operational_transaction_journals')->insert([
            'transaction_id' => $expenseTransaction,
            'journal_entry_id' => $this->operationalJournal($owner->id, $asset, $expense, 'expense', '2.00'),
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $expenseCategory,
        ]);
        $this->assertDatabaseCount('operational_transaction_journals', 2);
    }

    public function test_linked_transaction_accounting_facts_are_immutable_while_unlinked_transactions_remain_updateable(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$account, $category, $transaction] = $this->legacy($owner->id);
        $otherAccount = DB::table('accounts')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Other account', 'type' => 'cash',
            'balance' => '0.00', 'currency' => config('accounting.currency'),
        ]);
        $otherCategory = DB::table('categories')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Other income', 'type' => 'income',
        ]);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $this->mappingRows($owner->id, $account, $category, $asset, $revenue);
        DB::table('operational_transaction_journals')->insert([
            'transaction_id' => $transaction, 'journal_entry_id' => $this->operationalJournal($owner->id, $asset, $revenue, 'income'),
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $category,
        ]);

        $original = DB::table('transactions')->where('id', $transaction)->first([
            'user_id', 'account_id', 'category_id', 'amount', 'type', 'date', 'description',
        ]);
        foreach ([
            ['amount' => '2.00'],
            ['type' => 'expense'],
            ['date' => '2026-10-07'],
            ['account_id' => $otherAccount],
            ['category_id' => $otherCategory],
            ['user_id' => $other->id],
        ] as $changes) {
            $this->rejects(fn () => DB::table('transactions')->where('id', $transaction)->update($changes), [1644]);
            $this->assertEquals($original, DB::table('transactions')->where('id', $transaction)->first([
                'user_id', 'account_id', 'category_id', 'amount', 'type', 'date', 'description',
            ]));
        }
        $this->rejects(fn () => DB::table('transactions')->where('id', $transaction)->delete(), [1451]);
        $this->assertEquals($original, DB::table('transactions')->where('id', $transaction)->first([
            'user_id', 'account_id', 'category_id', 'amount', 'type', 'date', 'description',
        ]));
        DB::table('transactions')->where('id', $transaction)->update(['description' => 'Presentation only']);
        $this->assertSame('Presentation only', DB::table('transactions')->where('id', $transaction)->value('description'));

        $unlinked = DB::table('transactions')->insertGetId([
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $category,
            'amount' => '3.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);
        $this->assertSame(1, DB::table('transactions')->where('id', $unlinked)->update([
            'user_id' => $other->id, 'account_id' => $otherAccount, 'category_id' => $otherCategory,
            'amount' => '4.00', 'type' => 'expense', 'date' => '2026-10-08', 'description' => 'Legacy edit',
        ]));
        $this->assertDatabaseHas('transactions', [
            'id' => $unlinked, 'user_id' => $other->id, 'account_id' => $otherAccount,
            'category_id' => $otherCategory, 'amount' => '4.00', 'type' => 'expense',
            'date' => '2026-10-08', 'description' => 'Legacy edit',
        ]);
    }

    public function test_mappings_are_editable_before_link_and_frozen_after_linked_posted_use(): void
    {
        $owner = User::factory()->create();
        [$account, $category, $transaction] = $this->legacy($owner->id);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $otherAsset = $this->chart($owner->id, 'OTHER-ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $otherRevenue = $this->chart($owner->id, 'OTHER-REVENUE', 'revenue');
        $this->mappingRows($owner->id, $account, $category, $asset, $revenue);
        DB::table('operational_account_mappings')->where('account_id', $account)->update(['chart_account_id' => $otherAsset]);
        DB::table('operational_category_mappings')->where('category_id', $category)->update([
            'chart_account_id' => $otherRevenue, 'cash_flow_activity' => 'investing',
        ]);
        $this->assertSame($otherAsset, DB::table('operational_account_mappings')->where('account_id', $account)->value('chart_account_id'));
        $this->assertSame('investing', DB::table('operational_category_mappings')->where('category_id', $category)->value('cash_flow_activity'));
        $journal = $this->operationalJournal($owner->id, $otherAsset, $otherRevenue, 'income');
        DB::table('operational_transaction_journals')->insert([
            'transaction_id' => $transaction, 'journal_entry_id' => $journal,
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $category,
        ]);
        $this->rejects(fn () => DB::table('operational_account_mappings')->where('account_id', $account)->update(['chart_account_id' => $asset]), [1644]);
        $this->rejects(fn () => DB::table('operational_category_mappings')->where('category_id', $category)->update(['chart_account_id' => $revenue]), [1644]);
        $this->rejects(fn () => DB::table('operational_category_mappings')->where('category_id', $category)->update(['cash_flow_activity' => 'operating']), [1644]);
        $this->rejects(fn () => DB::table('operational_account_mappings')->where('account_id', $account)->delete(), [1644]);
        $this->rejects(fn () => DB::table('operational_category_mappings')->where('category_id', $category)->delete(), [1644]);
        $this->assertSame($otherAsset, DB::table('operational_account_mappings')->where('account_id', $account)->value('chart_account_id'));
    }

    public function test_unlinked_mappings_can_be_removed_and_no_integration_rows_are_auto_populated(): void
    {
        $owner = User::factory()->create();
        [$account, $category] = $this->legacy($owner->id);
        $asset = $this->chart($owner->id, 'ASSET', 'asset');
        $revenue = $this->chart($owner->id, 'REVENUE', 'revenue');
        $this->mappingRows($owner->id, $account, $category, $asset, $revenue);
        $this->assertSame(1, DB::table('operational_account_mappings')->where('account_id', $account)->delete());
        $this->assertSame(1, DB::table('operational_category_mappings')->where('category_id', $category)->delete());
        $this->assertDatabaseCount('operational_account_mappings', 0);
        $this->assertDatabaseCount('operational_category_mappings', 0);
        $this->assertDatabaseCount('operational_transaction_journals', 0);
        $this->assertDatabaseCount('operational_accounting_integrations', 0);
        $this->assertDatabaseCount('operational_accounting_account_scope', 0);
        $this->assertDatabaseCount('operational_accounting_category_scope', 0);
    }
}
