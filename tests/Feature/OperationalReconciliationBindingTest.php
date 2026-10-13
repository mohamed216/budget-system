<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCompletedReconciliationBindings;
use Tests\Concerns\RefreshFinancialDatabase;
use Tests\TestCase;

class OperationalReconciliationBindingTest extends TestCase
{
    use CreatesCompletedReconciliationBindings;
    use RefreshFinancialDatabase;

    private function fixture(string $state = 'reconciling', string $strategy = 'balance_only'): array
    {
        return $this->completedBindingFixture([
            'user_id' => User::factory()->create()->id, 'state' => $state,
            'historical_transaction_strategy' => $strategy, 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 42, 'scope_reviewed_at' => '2026-10-10 09:00:00',
            'reconciled_at' => '2026-10-10 10:00:00.123456',
            'activated_at' => $state === 'active' ? '2026-10-10 11:00:00.123456' : null,
        ]);
    }

    private function rejects(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Invalid reconciliation binding was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertSame(1644, $exception->errorInfo[1]);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public static function boundStates(): array
    {
        return [['reconciling', 'balance_only'], ['ready', 'balance_only'], ['active', 'balance_only'],
            ['reconciling', 'historical_backfill'], ['ready', 'historical_backfill'], ['active', 'historical_backfill']];
    }

    #[DataProvider('boundStates')]
    public function test_owned_completed_exact_binding_is_accepted_without_live_scope_validation(string $state, string $strategy): void
    {
        $row = $this->fixture($state, $strategy);
        DB::table('operational_accounting_integrations')->insert($row);
        $this->assertDatabaseHas('operational_accounting_integrations', $row);
        $this->assertSame('2026-10-10 10:00:00.123456', DB::table('operational_accounting_integrations')->where('user_id', $row['user_id'])->value('reconciled_at'));
        foreach (['operational_accounting_account_scope', 'operational_accounting_category_scope',
            'operational_account_mappings', 'operational_category_mappings', 'journal_entries', 'operational_transaction_journals'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function mismatches(): array
    {
        return [
            'strategy' => [['historical_transaction_strategy' => 'historical_backfill'], 'metadata'],
            'NULL strategy' => [['historical_transaction_strategy' => null], 'metadata'],
            'currency' => [['accounting_currency' => 'USD'], 'metadata'],
            'currency case' => [['accounting_currency' => 'sar'], 'metadata'],
            'NULL currency' => [['accounting_currency' => null], 'metadata'],
            'boundary' => [['transaction_high_water_id' => 43], 'metadata'],
            'NULL boundary' => [['transaction_high_water_id' => null], 'metadata'],
            'scope timestamp' => [['scope_reviewed_at' => '2026-10-10 09:00:01'], 'metadata'],
            'NULL scope timestamp' => [['scope_reviewed_at' => null], 'metadata'],
            'microsecond completion' => [['reconciled_at' => '2026-10-10 10:00:00.123457'], 'metadata'],
            'missing microseconds' => [['reconciled_at' => '2026-10-10 10:00:00'], 'metadata'],
            'half reference' => [['reconciliation_reference' => null], 'paired'],
            'half timestamp' => [['reconciled_at' => null], 'paired'],
            'legacy' => [['state' => 'legacy'], 'Legacy'],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_invalid_insert_and_update_bindings_reject_atomically(array $changes, string $message): void
    {
        $row = $this->fixture();
        $this->rejects(fn () => DB::table('operational_accounting_integrations')->insert(array_replace($row, $changes)), $message);
        $this->assertDatabaseCount('operational_accounting_integrations', 0);
        DB::table('operational_accounting_integrations')->insert($row);
        $this->rejects(fn () => DB::table('operational_accounting_integrations')->where('user_id', $row['user_id'])->update($changes), $message);
        $this->assertDatabaseHas('operational_accounting_integrations', $row);
    }

    public function test_foreign_missing_uncompleted_and_case_variant_references_are_rejected(): void
    {
        $row = $this->fixture();
        $foreign = $this->fixture();
        foreach ([$foreign['reconciliation_reference'], (string) Str::uuid()] as $reference) {
            $this->rejects(fn () => DB::table('operational_accounting_integrations')->insert(array_replace($row, ['reconciliation_reference' => $reference])), 'owned completed');
        }
        // Deterministic lowercase letters make the case-only mismatch genuine.
        $reference = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $draft = DB::table('operational_reconciliation_reviews')->insertGetId([
            'user_id' => $row['user_id'], 'reference' => $reference,
            'historical_transaction_strategy' => 'historical_backfill', 'accounting_currency' => 'SAR',
            'transaction_high_water_id' => 42, 'scope_reviewed_at' => $row['scope_reviewed_at'], 'created_at' => '2000-01-01',
        ]);
        $draftRow = array_replace($row, ['historical_transaction_strategy' => 'historical_backfill', 'reconciliation_reference' => $reference]);
        $this->rejects(fn () => DB::table('operational_accounting_integrations')->insert($draftRow), 'owned completed');
        $this->assertDatabaseHas('operational_reconciliation_reviews', ['id' => $draft, 'reviewed_by' => null, 'completed_at' => null]);
        // Pairing/000015 prohibits constructing half-completed source headers.
        foreach ([['reviewed_by' => $row['user_id']], ['completed_at' => $row['reconciled_at']]] as $half) {
            $this->rejects(fn () => DB::table('operational_reconciliation_reviews')->where('id', $draft)->update($half), 'together');
        }
        DB::table('operational_reconciliation_reviews')->where('id', $draft)->update([
            'reviewed_by' => $row['user_id'], 'completed_at' => $row['reconciled_at'],
        ]);
        foreach ([strtoupper($reference), $reference.' '] as $wrongSpelling) {
            $this->rejects(fn () => DB::table('operational_accounting_integrations')->insert(array_replace($draftRow, ['reconciliation_reference' => $wrongSpelling])), 'spelling');
        }
        DB::table('operational_accounting_integrations')->insert($draftRow);
        $this->assertDatabaseHas('operational_accounting_integrations', $draftRow);
    }

    public function test_binding_updates_can_complete_reconciling_and_clear_only_both_fields_in_unready_states(): void
    {
        $row = $this->fixture();
        DB::table('operational_accounting_integrations')->insert(['user_id' => $row['user_id'], 'state' => 'reconciling']);
        $query = DB::table('operational_accounting_integrations')->where('user_id', $row['user_id']);
        $query->update($row);
        $query->update(['state' => 'ready']);
        $this->rejects(fn () => $query->update(['reconciliation_reference' => null, 'reconciled_at' => null]), 'requires a completed');
        $query->update(['state' => 'active', 'activated_at' => '2026-10-10 11:00:00']);
        $this->rejects(fn () => $query->update(['reconciliation_reference' => null, 'reconciled_at' => null]), 'requires a completed');
        // No application transition is implemented here; this fixture resets to
        // the pre-activation state to exercise stale-evidence clearing semantics.
        $query->delete();
        DB::table('operational_accounting_integrations')->insert($row);
        $query->update(['reconciliation_reference' => null, 'reconciled_at' => null]);
        $query->update(['state' => 'legacy']);
        $this->assertDatabaseHas('operational_accounting_integrations', ['user_id' => $row['user_id'],
            'state' => 'legacy', 'reconciliation_reference' => null, 'reconciled_at' => null]);
    }

    public function test_active_integration_cannot_fall_back_even_when_both_evidence_fields_are_cleared(): void
    {
        $row = $this->fixture('active');
        DB::table('operational_accounting_integrations')->insert($row);
        foreach (['legacy', 'reconciling', 'ready'] as $state) {
            $this->rejects(fn () => DB::table('operational_accounting_integrations')->where('user_id', $row['user_id'])->update([
                'state' => $state, 'reconciliation_reference' => null, 'reconciled_at' => null,
            ]), 'cannot fall back');
            $this->assertDatabaseHas('operational_accounting_integrations', $row);
        }
    }
}
