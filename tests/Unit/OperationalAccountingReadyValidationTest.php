<?php

namespace Tests\Unit;

use App\Accounting\Exceptions\AccountingConflict;
use App\OperationalAccounting\OperationalReconciliationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsReconciliationCompletionSnapshots;

class OperationalAccountingReadyValidationTest extends TestCase
{
    use BuildsReconciliationCompletionSnapshots;

    private function boundSnapshot(string $strategy = 'balance_only'): array
    {
        $s = $this->completionSnapshot($strategy);
        $s['review']['reviewed_by'] = 1;
        $s['review']['completed_at'] = '2026-10-11 10:00:00.123456';
        $s['gate']['reconciliation_reference'] = $s['review']['reference'];
        $s['gate']['reconciled_at'] = $s['review']['completed_at'];

        return $s;
    }

    public function test_completed_bound_reviews_share_the_full_validation_without_a_digest(): void
    {
        foreach (['balance_only', 'historical_backfill'] as $strategy) {
            $s = $this->boundSnapshot($strategy);
            $before = $s;
            (new OperationalReconciliationValidator)->validateBound($s, 'reconciling');
            $this->assertSame($before, $s, 'Validation never turns completed evidence into a draft.');
        }
    }

    public static function invalidBindings(): array
    {
        return [
            'missing gate' => [['gate'], null],
            'legacy' => [['gate', 'state'], 'legacy'],
            'already ready' => [['gate', 'state'], 'ready'],
            'active' => [['gate', 'state'], 'active'],
            'unknown' => [['gate', 'state'], 'unknown'],
            'missing reference' => [['gate', 'reconciliation_reference'], null],
            'missing time' => [['gate', 'reconciled_at'], null],
            'foreign review' => [['review', 'user_id'], 2],
            'unsealed' => [['review', 'completed_at'], null],
            'missing reviewer' => [['review', 'reviewed_by'], null],
            'different reviewer' => [['review', 'reviewed_by'], 2],
            'reference case' => [['gate', 'reconciliation_reference'], 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'],
            'reference spelling' => [['gate', 'reconciliation_reference'], 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
            'completion microseconds' => [['gate', 'reconciled_at'], '2026-10-11 10:00:00.123457'],
        ];
    }

    #[DataProvider('invalidBindings')]
    public function test_invalid_binding_is_rejected(array $path, mixed $value): void
    {
        $this->rejectMutation($path, $value);
    }

    public static function staleSnapshots(): array
    {
        // The same financial rule mutations used for draft completion must also
        // reject a genuinely sealed/bound snapshot, not a synthetic draft.
        $cases = self::invalidReconciliationSnapshots();
        unset($cases['bound gate']);
        $cases['missing activity review time'] = [['sources', 'operational_category_mappings', 0, 'reviewed_at'], null];
        $cases['account scope'] = [['sources', 'operational_accounting_account_scope'], []];
        $cases['category scope'] = [['sources', 'operational_accounting_category_scope'], []];
        $cases['missing membership'] = [['evidence', 'journals'], []];
        $cases['invalid provenance'] = [['sources', 'journal_entries', 0, 'reversal_of_id'], 999];

        return $cases;
    }

    #[DataProvider('staleSnapshots')]
    public function test_bound_review_rejects_stale_financial_snapshot(array $path, mixed $value): void
    {
        $this->rejectMutation($path, $value);
    }

    private function rejectMutation(array $path, mixed $value): void
    {
        $s = $this->boundSnapshot();
        $node = &$s;
        foreach ($path as $key) {
            $node = &$node[$key];
        }
        $node = $value;
        unset($node);
        $this->expectException(AccountingConflict::class);
        (new OperationalReconciliationValidator)->validateBound($s, 'reconciling');
    }

    public function test_shared_target_ambiguity_rejects_even_after_completion(): void
    {
        $s = $this->boundSnapshot();
        $s['sources']['accounts'][] = ['id' => 3, 'user_id' => 1, 'balance' => '0.00', 'currency' => 'SAR'];
        $s['sources']['operational_account_mappings'][] = ['account_id' => 3, 'user_id' => 1, 'chart_account_id' => 10];
        $s['sources']['operational_accounting_account_scope'][] = ['account_id' => 3, 'user_id' => 1];
        $s['evidence']['accounts'][] = array_replace($s['evidence']['accounts'][0], ['account_id' => 3, 'stored_balance_snapshot' => '40.00']);
        $s['sources']['accounts'][1]['balance'] = '40.00';
        $this->expectException(AccountingConflict::class);
        (new OperationalReconciliationValidator)->validateBound($s, 'reconciling');
    }
}
