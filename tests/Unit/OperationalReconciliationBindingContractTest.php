<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationalReconciliationBindingContractTest extends TestCase
{
    private function migration(): object
    {
        return require __DIR__.'/../../database/migrations/2026_10_10_000016_bind_operational_reconciliation_reviews.php';
    }

    public static function pairClauses(): array
    {
        return [
            ['(reconciliation_reference IS NULL AND reconciled_at IS NULL) OR (reconciliation_reference IS NOT NULL AND reconciled_at IS NOT NULL)', true],
            ['(((`reconciliation_reference` is null) and (`reconciled_at` is null)) or ((`reconciliation_reference` is not null) and (`reconciled_at` is not null)))', true],
            ['1 = 1', false],
            ['reconciliation_reference IS NULL OR reconciled_at IS NULL', false],
            ['(reconciliation_reference IS NULL AND reconciled_at IS NULL) OR (reconciliation_reference IS NOT NULL OR reconciled_at IS NOT NULL)', false],
            ['(reconciliation_reference IS NULL AND reconciled_at IS NULL) OR (reconciliation_reference IS NOT NULL AND reconciled_at IS NULL)', false],
            ['(reconciliation_reference IS NULL AND reconciled_at IS NULL) OR (reconciliation_reference IS NOT NULL AND reconciled_at IS NOT NULL) OR 1 = 1', false],
        ];
    }

    #[DataProvider('pairClauses')]
    public function test_pair_check_matching_preserves_null_and_boolean_structure(string $expression, bool $expected): void
    {
        $migration = $this->migration();
        $this->assertSame($expected, (new \ReflectionMethod($migration, 'matchesPairCheck'))->invoke($migration, $expression));
    }

    public function test_trigger_validation_locks_only_completed_review_and_compares_binary_metadata(): void
    {
        $migration = $this->migration();
        $definitions = (new \ReflectionMethod($migration, 'definitions'))->invoke($migration);
        $this->assertSame(['oai_review_bi', 'oai_review_bu'], array_keys($definitions));
        foreach ($definitions as [$event, $body]) {
            $this->assertContains($event, ['INSERT', 'UPDATE']);
            $this->assertSame(1, substr_count($body, 'SELECT '));
            $this->assertStringContainsString('FROM operational_reconciliation_reviews', $body);
            $this->assertStringContainsString('WHERE user_id = NEW.user_id AND reference = NEW.reconciliation_reference FOR SHARE;', $body);
            $this->assertStringNotContainsString('NOWAIT', $body);
            foreach (['FROM users', 'FROM accounts', 'FROM categories', 'FROM chart_of_accounts', 'scope as', 'FROM operational_account_mappings'] as $earlierRead) {
                $this->assertStringNotContainsString($earlierRead, $body);
            }
            foreach (['reconciliation_reference', 'historical_transaction_strategy', 'accounting_currency'] as $binaryField) {
                $this->assertStringContainsString('CAST(NEW.'.$binaryField.' AS BINARY)', $body);
            }
            $this->assertStringContainsString('review_reviewer IS NULL OR review_completed IS NULL', $body);
            $this->assertStringContainsString('NEW.transaction_high_water_id <=> review_boundary', $body);
            $this->assertStringContainsString('NEW.scope_reviewed_at <=> review_scope', $body);
            $this->assertStringContainsString('NEW.reconciled_at <=> review_completed', $body);
            $this->assertStringContainsString("('reconciling', 'ready', 'active')", $body);
        }
    }
}
