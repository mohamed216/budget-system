<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalAccountingMigrationUpgradeTest extends TestCase
{
    public function test_schema_upgrade_does_not_claim_or_populate_existing_operational_data(): void
    {
        // MySQL DDL commits implicitly. Use only the isolated test database and no transactional fixtures.
        $this->assertSame(0, DB::transactionLevel());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $owner = User::factory()->create();
        $account = DB::table('accounts')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Pre-upgrade account', 'type' => 'bank',
            'balance' => '20500.00', 'currency' => config('accounting.currency'),
        ]);
        $category = DB::table('categories')->insertGetId([
            'user_id' => $owner->id, 'name' => 'Pre-upgrade income', 'type' => 'income',
        ]);
        $transaction = DB::table('transactions')->insertGetId([
            'user_id' => $owner->id, 'account_id' => $account, 'category_id' => $category,
            'amount' => '500.00', 'type' => 'income', 'date' => '2026-10-06',
        ]);
        $legacyRows = [
            'accounts' => (array) DB::table('accounts')->where('id', $account)->first(),
            'categories' => (array) DB::table('categories')->where('id', $category)->first(),
            'transactions' => (array) DB::table('transactions')->where('id', $transaction)->first(),
        ];
        $migrations = [];
        // Evidence FKs must be rolled back before the original owner reference keys.
        for ($number = 1; $number <= 14; $number++) {
            $path = glob(database_path(sprintf('migrations/2026_10_10_%06d_*.php', $number)));
            $this->assertCount(1, $path);
            $migrations[$number] = require $path[0];
        }

        try {
            foreach (array_reverse($migrations, true) as $migration) {
                $migration->down();
            }
            $this->assertSame('20500.00', DB::table('accounts')->where('id', $account)->value('balance'));
            $this->assertSame('500.00', DB::table('transactions')->where('id', $transaction)->value('amount'));

            foreach ($migrations as $migration) {
                $migration->up();
            }
            foreach (['operational_account_mappings', 'operational_category_mappings',
                'operational_transaction_journals', 'operational_accounting_integrations',
                'operational_accounting_account_scope', 'operational_accounting_category_scope',
                'operational_reconciliation_reviews', 'operational_reconciliation_charts',
                'operational_reconciliation_accounts', 'operational_reconciliation_categories',
                'operational_reconciliation_journals', 'operational_reconciliation_attributions'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
            $this->assertSame($owner->id, DB::table('transactions')->where('id', $transaction)->value('user_id'));
            foreach ($legacyRows as $table => $row) {
                $this->assertSame($row, (array) DB::table($table)->where('id', $row['id'])->first(), $table);
            }
            $strategyColumn = collect(Schema::getColumns('operational_accounting_integrations'))->firstWhere('name', 'historical_transaction_strategy');
            $this->assertNotNull($strategyColumn);
            $this->assertTrue($strategyColumn['nullable']);
            $this->assertNull($strategyColumn['default']);
            $scopeReviewColumn = collect(Schema::getColumns('operational_accounting_integrations'))->firstWhere('name', 'scope_reviewed_at');
            $this->assertNotNull($scopeReviewColumn);
            $this->assertTrue($scopeReviewColumn['nullable']);
            $this->assertNull($scopeReviewColumn['default']);
            DB::table('operational_accounting_integrations')->insert(['user_id' => $owner->id]);
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->value('historical_transaction_strategy'));
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->value('scope_reviewed_at'));
            foreach ([
                [7, 'operational_accounting_account_scope', 'account_id', $account, 'account'],
                [8, 'operational_accounting_category_scope', 'category_id', $category, 'category'],
            ] as [$number, $table, $key, $id, $label]) {
                try {
                    $migrations[$number]->up();
                    $this->fail('Scope migration accepted an existing table.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame("Operational accounting {$label} scope already exists; inspect schema and migration history.", $exception->getMessage());
                }
                DB::table($table)->insert([$key => $id, 'user_id' => $owner->id]);
                try {
                    $migrations[$number]->down();
                    $this->fail('Scope migration dropped selected records.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame("Refusing to drop populated or reviewed operational accounting {$label} scope.", $exception->getMessage());
                }
                $this->assertDatabaseHas($table, [$key => $id, 'user_id' => $owner->id]);
                DB::table($table)->where($key, $id)->delete();
            }
            DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->update(['scope_reviewed_at' => now()]);
            foreach ([7 => 'account', 8 => 'category'] as $number => $label) {
                $this->assertDatabaseCount("operational_accounting_{$label}_scope", 0);
                try {
                    $migrations[$number]->down();
                    $this->fail('Scope migration dropped a reviewed empty scope.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame("Refusing to drop populated or reviewed operational accounting {$label} scope.", $exception->getMessage());
                }
                $this->assertTrue(Schema::hasTable("operational_accounting_{$label}_scope"));
            }
            foreach (['up', 'down'] as $direction) {
                try {
                    $migrations[5]->{$direction}();
                    $this->fail('Integration migration did not fail closed for existing integration state.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame($direction === 'up'
                        ? 'Operational accounting integration states already exist; inspect schema and migration history.'
                        : 'Refusing to drop non-empty operational accounting integration states.', $exception->getMessage());
                }
            }
            $this->assertSame('legacy', DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->value('state'));
            $this->assertNull(DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->value('historical_transaction_strategy'));
        } finally {
            // Restore any interrupted schema transition before removing only this test's fixtures.
            $migrations[1]->up();
            foreach ([
                2 => 'operational_account_mappings', 3 => 'operational_category_mappings',
                4 => 'operational_transaction_journals', 5 => 'operational_accounting_integrations',
                7 => 'operational_accounting_account_scope', 8 => 'operational_accounting_category_scope',
            ] as $number => $table) {
                if (! Schema::hasTable($table)) {
                    $migrations[$number]->up();
                }
            }
            $migrations[6]->up();
            foreach ([
                9 => 'operational_reconciliation_reviews', 10 => 'operational_reconciliation_charts',
                11 => 'operational_reconciliation_accounts', 12 => 'operational_reconciliation_categories',
                13 => 'operational_reconciliation_journals', 14 => 'operational_reconciliation_attributions',
            ] as $number => $table) {
                if (! Schema::hasTable($table)) {
                    $migrations[$number]->up();
                }
            }
            DB::table('operational_accounting_account_scope')->where('account_id', $account)->delete();
            DB::table('operational_accounting_category_scope')->where('category_id', $category)->delete();
            DB::table('operational_accounting_integrations')->where('user_id', $owner->id)->delete();
            DB::table('transactions')->where('id', $transaction)->delete();
            DB::table('categories')->where('id', $category)->delete();
            DB::table('accounts')->where('id', $account)->delete();
            $owner->delete();
        }
    }
}
