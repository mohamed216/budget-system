<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesCompletedReconciliationBindings
{
    private array $bindingFixtureReviewIds = [];

    private array $bindingFixtureReferences = [];

    private function completedBindingFixture(array $row): array
    {
        if ($row['state'] === 'legacy' || ($row['scope_reviewed_at'] ?? null) === null
            || ($row['transaction_high_water_id'] ?? null) === null || ($row['historical_transaction_strategy'] ?? null) === null) {
            return array_replace($row, ['reconciliation_reference' => null, 'reconciled_at' => null]);
        }
        $reference = (string) Str::uuid();
        $row['accounting_currency'] ??= config('accounting.currency');
        $row['reconciled_at'] ??= '2026-10-10 10:00:00.000000';
        $id = DB::table('operational_reconciliation_reviews')->insertGetId([
            'user_id' => $row['user_id'], 'reference' => $reference,
            'historical_transaction_strategy' => $row['historical_transaction_strategy'],
            'accounting_currency' => $row['accounting_currency'],
            'transaction_high_water_id' => $row['transaction_high_water_id'],
            'scope_reviewed_at' => $row['scope_reviewed_at'], 'created_at' => '2000-01-01 00:00:00',
            'balance_only_disclosure_code' => $row['historical_transaction_strategy'] === 'balance_only' ? 'balance_only_legacy_history_v1' : null,
            'balance_only_disclosure_text' => $row['historical_transaction_strategy'] === 'balance_only' ? 'Test review: pre-cutover income statement is incomplete.' : null,
        ]);
        $this->bindingFixtureReviewIds[$id] = $row['user_id'];
        $this->bindingFixtureReferences[$row['user_id']] = $reference;
        DB::table('operational_reconciliation_reviews')->where('id', $id)->update([
            'reviewed_by' => $row['user_id'], 'completed_at' => $row['reconciled_at'],
        ]);

        return array_replace($row, ['reconciliation_reference' => $reference]);
    }

    private function cleanupBindingFixtures(array $ownerIds): void
    {
        // Only committed test headers created by this helper, with no children.
        // Caller removes integrations first. All restrictive FKs stay enabled.
        $ids = array_keys(array_filter($this->bindingFixtureReviewIds, fn ($owner) => in_array($owner, $ownerIds, true)));
        if ($ids === []) {
            return;
        }
        $migration = require database_path('migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php');
        $definition = (new \ReflectionMethod($migration, 'definitions'))->invoke($migration)['orr_header_bd'];
        $actual = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where('TRIGGER_NAME', 'orr_header_bd')->first();
        $this->assertNotNull($actual);
        $this->assertSame(trim($definition[2]), trim($actual->ACTION_STATEMENT));
        DB::unprepared('DROP TRIGGER orr_header_bd');
        try {
            foreach (array_reverse($ids) as $id) {
                DB::table('operational_reconciliation_reviews')->where('id', $id)->delete();
                $this->assertDatabaseMissing('operational_reconciliation_reviews', ['id' => $id]);
            }
        } finally {
            DB::unprepared('CREATE TRIGGER orr_header_bd BEFORE DELETE ON operational_reconciliation_reviews FOR EACH ROW '.$definition[2]);
        }
    }
}
