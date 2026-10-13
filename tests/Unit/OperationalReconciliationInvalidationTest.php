<?php

namespace Tests\Unit;

use App\Accounting\Exceptions\AccountingConflict;
use App\OperationalAccounting\OperationalReconciliationInvalidation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationalReconciliationInvalidationTest extends TestCase
{
    public static function policies(): array
    {
        $clear = ['reconciliation_reference' => null, 'reconciled_at' => null];

        return [
            'absent' => [null, null, null, []],
            'legacy unbound' => ['legacy', null, null, []],
            'legacy bound' => ['legacy', 'reference', 'timestamp', null],
            'reconciling unbound' => ['reconciling', null, null, []],
            'reconciling bound' => ['reconciling', 'reference', 'timestamp', $clear],
            'ready bound' => ['ready', 'reference', 'timestamp', ['state' => 'reconciling'] + $clear],
            'ready unbound' => ['ready', null, null, null],
            'ready half pair' => ['ready', 'reference', null, null],
            'reconciling half pair' => ['reconciling', null, 'timestamp', null],
            'legacy half pair' => ['legacy', 'reference', null, null],
            'active bound' => ['active', 'reference', 'timestamp', null],
            'active unbound' => ['active', null, null, null],
            'unknown' => ['unexpected', null, null, null],
        ];
    }

    #[DataProvider('policies')]
    public function test_exact_policy_without_queries_writes_or_input_mutation(?string $state, ?string $reference, ?string $time, ?array $expected): void
    {
        $gate = $state === null ? null : (object) [
            'state' => $state, 'reconciliation_reference' => $reference, 'reconciled_at' => $time,
            'historical_transaction_strategy' => 'balance_only', 'accounting_currency' => 'USD',
            'transaction_high_water_id' => 42, 'scope_reviewed_at' => 'scope', 'activated_at' => 'activation',
        ];
        $before = $gate === null ? null : clone $gate;
        try {
            $changes = (new OperationalReconciliationInvalidation)->changesFor($gate);
            $this->assertNotNull($expected, 'Invalid state was accepted.');
            $this->assertSame($expected, $changes);
            $this->assertArrayNotHasKey('updated_at', $changes);
            foreach (['historical_transaction_strategy', 'accounting_currency', 'transaction_high_water_id', 'scope_reviewed_at', 'activated_at'] as $field) {
                $this->assertArrayNotHasKey($field, $changes);
            }
        } catch (AccountingConflict) {
            $this->assertNull($expected, 'Valid state was rejected.');
        }
        $this->assertEquals($before, $gate);
    }
}
