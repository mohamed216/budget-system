<?php

namespace Tests\Feature;

use App\Accounting\Actions\CreateChartAccount;
use App\Accounting\Actions\DeleteJournalDraft;
use App\Accounting\Actions\SaveJournalDraft;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AccountingTriggerMigrationTest extends TestCase
{
    // Trigger DDL commits implicitly. Never mix these tests with transactional fixtures.
    private function migration(): object
    {
        $this->assertSame(0, DB::transactionLevel());

        return require database_path('migrations/2026_10_06_000004_protect_posted_journals.php');
    }

    private function triggers(): array
    {
        return DB::select("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME LIKE 'accounting_%' ORDER BY TRIGGER_NAME", [DB::connection()->getDatabaseName()]);
    }

    public function test_expected_trigger_identities_and_safe_rollback_without_data_changes(): void
    {
        $migration = $this->migration();
        $migration->up();
        $before = $this->triggers();
        $expected = [
            'accounting_je_draft_bi' => ['journal_entries', 'INSERT'],
            'accounting_je_immutable_bd' => ['journal_entries', 'DELETE'],
            'accounting_je_immutable_bu' => ['journal_entries', 'UPDATE'],
            'accounting_jl_immutable_bd' => ['journal_lines', 'DELETE'],
            'accounting_jl_immutable_bi' => ['journal_lines', 'INSERT'],
            'accounting_jl_immutable_bu' => ['journal_lines', 'UPDATE'],
            'accounting_obb_draft_bi' => ['opening_balance_batches', 'INSERT'],
            'accounting_obb_immutable_bd' => ['opening_balance_batches', 'DELETE'],
            'accounting_obb_immutable_bu' => ['opening_balance_batches', 'UPDATE'],
            'accounting_obl_immutable_bd' => ['opening_balance_lines', 'DELETE'],
            'accounting_obl_immutable_bi' => ['opening_balance_lines', 'INSERT'],
            'accounting_obl_immutable_bu' => ['opening_balance_lines', 'UPDATE'],
        ];
        $this->assertCount(count($expected), $before);
        foreach ($before as $trigger) {
            $this->assertArrayHasKey($trigger->TRIGGER_NAME, $expected);
            $this->assertSame($expected[$trigger->TRIGGER_NAME], [$trigger->EVENT_OBJECT_TABLE, $trigger->EVENT_MANIPULATION]);
            $this->assertSame('BEFORE', $trigger->ACTION_TIMING);
            if (in_array($trigger->EVENT_OBJECT_TABLE, ['journal_lines', 'opening_balance_lines'], true)) {
                $this->assertStringContainsString('FOR SHARE NOWAIT', $trigger->ACTION_STATEMENT);
                $this->assertStringNotContainsString('FOR UPDATE', $trigger->ACTION_STATEMENT);
            }
        }
        $openingTriggers = array_values(array_filter($before, fn ($trigger) => in_array($trigger->EVENT_OBJECT_TABLE, ['opening_balance_batches', 'opening_balance_lines'], true)));
        $counts = [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(),
            DB::table('opening_balance_batches')->count(), DB::table('opening_balance_lines')->count()];
        try {
            $migration->down();
            $this->assertEquals($openingTriggers, $this->triggers());
            $migration->down();
            $this->assertEquals($openingTriggers, $this->triggers());
        } finally {
            $migration->up();
        }
        $migration->up();
        $this->assertEquals($before, $this->triggers());
        $this->assertSame($counts, [DB::table('journal_entries')->count(), DB::table('journal_lines')->count(),
            DB::table('opening_balance_batches')->count(), DB::table('opening_balance_lines')->count()]);
    }

    public function test_conflicting_identity_or_body_fails_preflight_without_dropping_other_triggers(): void
    {
        $migration = $this->migration();
        $migration->up();
        $before = $this->triggers();
        foreach (['chart_of_accounts', 'journal_entries'] as $table) {
            DB::unprepared('DROP TRIGGER accounting_je_immutable_bu');
            try {
                DB::unprepared("CREATE TRIGGER accounting_je_immutable_bu BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN SET @accounting_trigger_probe = 1; END");
                $conflicting = $this->triggers();
                foreach (['up', 'down'] as $method) {
                    try {
                        $migration->{$method}();
                        $this->fail('Expected conflicting trigger to fail preflight.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringContainsString('Unexpected trigger accounting_je_immutable_bu', $exception->getMessage());
                    }
                    $this->assertEquals($conflicting, $this->triggers());
                }
            } finally {
                DB::unprepared('DROP TRIGGER IF EXISTS accounting_je_immutable_bu');
                $migration->up();
            }
        }
        $this->assertEquals($before, $this->triggers());
    }

    public function test_concurrent_raw_line_update_cannot_bypass_locked_header(): void
    {
        $this->migration()->up();
        $owner = User::factory()->create();
        $account = (new CreateChartAccount)->execute($owner, '1000', 'Concurrency fixture', 'asset');
        $journal = (new SaveJournalDraft)->execute($owner, '2026-10-06', config('accounting.currency'), [
            ['chart_account_id' => $account->id, 'debit' => '1.00', 'credit' => '0.00'],
        ]);
        config(['database.connections.accounting_trigger_probe' => config('database.connections.mysql_testing')]);
        $connection = DB::connection('accounting_trigger_probe');
        try {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::beginTransaction();
            DB::table('journal_entries')->where('id', $journal->id)->lockForUpdate()->first();
            try {
                $connection->table('journal_lines')->where('id', $journal->lines->first()->id)->update(['description' => 'Concurrent mutation']);
                $this->fail('Concurrent raw line mutation must not bypass the locked header.');
            } catch (QueryException $exception) {
                // InnoDB may wait on an existing FK lock before reaching the trigger.
                $this->assertContains($exception->errorInfo[1], [1205, 1213, 1644]);
                if ($exception->errorInfo[1] === 1644) {
                    $this->assertSame('45000', $exception->errorInfo[0]);
                    $this->assertStringContainsString('Journal header is busy', $exception->getMessage());
                }
            }
            $this->assertNull($journal->lines->first()->fresh()->description);
        } finally {
            DB::rollBack();
            DB::purge('accounting_trigger_probe');
            // Remove only this test's committed draft fixtures, with all protections enabled.
            (new DeleteJournalDraft)->execute($owner, $journal->id);
            $account->delete();
            $owner->delete();
        }
    }
}
