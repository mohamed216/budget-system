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
        $migrations = [];
        for ($number = 1; $number <= 6; $number++) {
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
                'operational_transaction_journals', 'operational_accounting_integrations'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
            $this->assertSame($owner->id, DB::table('transactions')->where('id', $transaction)->value('user_id'));
        } finally {
            // Restore any interrupted schema transition before removing only this test's fixtures.
            $migrations[1]->up();
            foreach ([
                2 => 'operational_account_mappings', 3 => 'operational_category_mappings',
                4 => 'operational_transaction_journals', 5 => 'operational_accounting_integrations',
            ] as $number => $table) {
                if (! Schema::hasTable($table)) {
                    $migrations[$number]->up();
                }
            }
            $migrations[6]->up();
            DB::table('transactions')->where('id', $transaction)->delete();
            DB::table('categories')->where('id', $category)->delete();
            DB::table('accounts')->where('id', $account)->delete();
            $owner->delete();
        }
    }
}
