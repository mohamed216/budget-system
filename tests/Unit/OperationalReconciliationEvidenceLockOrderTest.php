<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationalReconciliationEvidenceLockOrderTest extends TestCase
{
    private function definitions(): array
    {
        $migration = require __DIR__.'/../../database/migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php';

        return (new \ReflectionMethod($migration, 'definitions'))->invoke($migration);
    }

    public static function children(): array
    {
        $cases = [];
        foreach (['charts' => 'chart_of_accounts', 'accounts' => 'accounts', 'categories' => 'categories',
            'journals' => 'journal_entries', 'attributions' => 'journal_entries'] as $child => $firstSource) {
            foreach (['bi', 'bu', 'bd'] as $event) {
                $cases[$child.' '.$event] = [$child, $event, $firstSource];
            }
        }

        return $cases;
    }

    #[DataProvider('children')]
    public function test_all_child_source_reads_precede_parent_current_read(string $child, string $event, string $source): void
    {
        $body = $this->definitions()['orr_'.$child.'_'.$event][2];
        $this->assertStringNotContainsString('NOWAIT', $body);
        $this->assertStringNotContainsString('FOR 3572', $body);
        preg_match_all('/SELECT\b[\s\S]*?FOR SHARE;/', $body, $reads);
        $this->assertNotEmpty($reads[0]);
        $this->assertStringContainsString('FROM '.$source, $reads[0][0]);
        $parent = array_pop($reads[0]);
        $this->assertStringContainsString('FROM operational_reconciliation_reviews', $parent);
        $this->assertStringContainsString('completed_at', $parent);
        foreach ($reads[0] as $read) {
            $this->assertStringNotContainsString('FROM operational_reconciliation_reviews', $read);
        }
        $this->assertStringContainsString('Sealed reconciliation evidence cannot be mutated', $body);
    }

    public function test_header_does_not_discover_or_lock_owner_reviewer_sources_while_sealing(): void
    {
        $definitions = $this->definitions();
        $insert = $definitions['orr_header_bi'][2];
        $update = $definitions['orr_header_bu'][2];
        $this->assertStringNotContainsString('FROM users', $insert);
        $this->assertStringContainsString('FROM operational_reconciliation_reviews', $insert);
        $this->assertStringContainsString('FOR SHARE;', $insert);
        $this->assertStringNotContainsString('SELECT ', $update);
        $this->assertStringContainsString('Sealed reconciliation header cannot be updated', $update);
    }

    public function test_mutable_multiple_targets_are_locked_in_stable_id_order(): void
    {
        $definitions = $this->definitions();
        foreach (['accounts', 'categories', 'attributions'] as $child) {
            $body = $definitions['orr_'.$child.'_bu'][2];
            $this->assertStringContainsString('SET first_source = LEAST(', $body);
            $this->assertStringContainsString('SET last_source = GREATEST(', $body);
            $this->assertStringContainsString('IF last_source <> first_source THEN', $body);
        }
        $body = $definitions['orr_attributions_bu'][2];
        $this->assertStringContainsString('COALESCE(NEW.account_id, OLD.account_id)', $body);
        $this->assertLessThan(strpos($body, 'FROM journal_lines'), strpos($body, 'FROM journal_entries'));
    }
}
