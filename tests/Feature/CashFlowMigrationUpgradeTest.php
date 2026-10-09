<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashFlowMigrationUpgradeTest extends TestCase
{
    public function test_existing_chart_account_remains_unreviewed_when_cash_role_is_added(): void
    {
        // ALTER TABLE commits implicitly; keep this test outside transactional fixtures.
        $this->assertSame(0, DB::transactionLevel());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $owner = User::factory()->create();
        $account = $owner->chartAccounts()->create([
            'code' => 'CASH-FLOW-MIGRATION-PROBE', 'name' => 'Existing account', 'type' => 'asset',
        ]);
        $migration = require database_path('migrations/2026_10_09_000001_add_cash_role_to_chart_accounts.php');
        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'cash_role'));
            $migration->up();
            $this->assertNull(DB::table('chart_of_accounts')->where('id', $account->id)->value('cash_role'));
        } finally {
            if (! Schema::hasColumn('chart_of_accounts', 'cash_role')) {
                $migration->up();
            }
            $account->delete();
            $owner->delete();
        }
    }
}
