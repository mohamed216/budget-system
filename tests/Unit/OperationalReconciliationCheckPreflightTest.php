<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationalReconciliationCheckPreflightTest extends TestCase
{
    private static function migration(): object
    {
        return require __DIR__.'/../../database/migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php';
    }

    private static function expressions(): array
    {
        $migration = self::migration();

        return (new \ReflectionMethod($migration, 'checkExpressions'))->invoke($migration);
    }

    private function checkMatches(string $name, string $expression): bool
    {
        $migration = self::migration();

        return (new \ReflectionMethod($migration, 'matchesCheckExpression'))->invoke($migration, $name, $expression);
    }

    public static function equivalentExpressions(): array
    {
        $cases = [];
        foreach (self::expressions() as $name => $expression) {
            $cases[$name.' foundation'] = [$name, $expression];
            $cases[$name.' grouping and keyword case'] = [$name, "(\n( ".preg_replace_callback(
                "/'[^']*'|\b[a-z_][a-z_0-9]*\b/i",
                fn ($match) => str_starts_with($match[0], "'") ? $match[0] : strtoupper($match[0]),
                $expression
            )." )\n)"];
            $canonical = str_replace('AS BINARY', 'AS CHAR CHARSET binary', $expression);
            $cases[$name.' MySQL introducers'] = [$name, preg_replace_callback(
                "/'([^']*)'/", fn ($match) => "_utf8mb4'".$match[1]."'", $canonical
            )];
            $cases[$name.' MySQL escaped metadata literals'] = [$name, preg_replace_callback(
                "/'([^']*)'/", fn ($match) => "_utf8mb4\\'".$match[1]."\\'", $canonical
            )];
        }
        $cases['quoted identifiers and decimal zero'] = ['orat_amount_check', '((`amount`) > (000.000))'];
        $cases['numeric IN formatting'] = ['orc_active_check', '`is_active_snapshot` IN ((00.0), (01.00))'];
        $cases['reference numeric formatting'] = ['orr_reference_check', str_replace('= 36', '= 036.00', self::expressions()['orr_reference_check'])];
        $cases['binary cast character set spelling'] = ['orr_strategy_check', "CAST(`historical_transaction_strategy` AS CHAR CHARACTER SET `binary`) IN (_ascii'historical_backfill', _ascii'balance_only')"];
        $cases['binary coerced IN literals'] = ['orr_strategy_check', "CAST(historical_transaction_strategy AS CHAR CHARSET binary) IN (_binary'historical_backfill', _binary'balance_only')"];
        $cases['binary coerced equality literals'] = ['orc_cash_asset_check', "CAST(cash_role_snapshot AS BINARY) = _binary\\'non_cash\\' OR CAST(type_snapshot AS BINARY) = _binary'asset'"];
        $cases['associative boolean grouping'] = ['orr_disclosure_check', str_replace(
            'AND balance_only_disclosure_code IS NULL AND balance_only_disclosure_text IS NULL)',
            'AND (balance_only_disclosure_code IS NULL AND balance_only_disclosure_text IS NULL))',
            self::expressions()['orr_disclosure_check']
        )];

        return $cases;
    }

    #[DataProvider('equivalentExpressions')]
    public function test_only_formatting_equivalent_checks_are_accepted(string $name, string $expression): void
    {
        $this->assertTrue($this->checkMatches($name, $expression), $expression);
    }

    public static function alteredExpressions(): array
    {
        $cases = [];
        foreach (self::expressions() as $name => $expression) {
            $cases[$name.' tautology'] = [$name, '1'];
            $cases[$name.' OR bypass'] = [$name, '('.$expression.') OR 1'];
        }
        foreach ([
            'zero accepted' => ['orat_amount_check', 'amount >= 0'],
            'negative threshold' => ['orat_amount_check', 'amount > -1'],
            'different source' => ['orat_amount_check', 'account_id > 0'],
            'changed numeric value' => ['orat_amount_check', 'amount > 0.01'],
            'trailing content' => ['orat_amount_check', 'amount > 0;'],
            'comment' => ['orat_amount_check', 'amount /* ignored? */ > 0'],
            'unbalanced grouping' => ['orat_amount_check', '(amount > 0'],
            'wrong NULL test' => ['ora_rationale_check', "rationale IS NOT NULL OR REGEXP_LIKE(rationale, '[^[:space:]]', 'c')"],
            'changed regex case' => ['orr_currency_check', "REGEXP_LIKE(accounting_currency, '^[a-z]{3}$', 'c')"],
            'case insensitive regex' => ['orr_currency_check', "REGEXP_LIKE(accounting_currency, '^[A-Z]{3}$', 'i')"],
            'regex extra argument' => ['orr_currency_check', "REGEXP_LIKE(accounting_currency, '^[A-Z]{3}$', 'c', 1)"],
            'nonbinary cast' => ['orr_strategy_check', "CAST(historical_transaction_strategy AS CHAR CHARSET utf8mb4) IN ('historical_backfill', 'balance_only')"],
            'truncating binary cast' => ['orr_strategy_check', "CAST(historical_transaction_strategy AS BINARY(1)) IN ('historical_backfill', 'balance_only')"],
            'literal case changed' => ['orr_strategy_check', "CAST(historical_transaction_strategy AS BINARY) IN ('HISTORICAL_BACKFILL', 'balance_only')"],
            'literal whitespace changed' => ['orr_strategy_check', "CAST(historical_transaction_strategy AS BINARY) IN ('historical_backfill ', 'balance_only')"],
            'wrong literal charset' => ['orr_strategy_check', "CAST(historical_transaction_strategy AS BINARY) IN (_utf16'historical_backfill', 'balance_only')"],
            'binary regex literal is not formatting' => ['orr_currency_check', "REGEXP_LIKE(accounting_currency, _binary'^[A-Z]{3}$', 'c')"],
            'backslash inside literal' => ['orr_strategy_check', "CAST(historical_transaction_strategy AS BINARY) IN (_utf8mb4\\'historical_back\\fill\\', 'balance_only')"],
            'unknown constraint' => ['unknown', 'amount > 0'],
        ] as $label => $case) {
            $cases[$label] = $case;
        }
        $target = self::expressions()['orat_target_check'];
        $cases['target missing purpose NULL guard'] = ['orat_target_check', str_replace('AND purpose IS NOT NULL', '', $target)];
        $cases['target wrong account NULL guard'] = ['orat_target_check', str_replace('account_id IS NOT NULL', 'account_id IS NULL', $target)];
        $cases['target different purpose'] = ['orat_target_check', str_replace('existing_history', 'historical_transaction', $target)];
        $cases['excluded missing rationale guard'] = ['orat_target_check', str_replace('AND rationale IS NOT NULL', '', $target)];
        $cases['boolean precedence changed'] = ['orat_target_check', str_replace(
            "AND (rationale IS NULL OR REGEXP_LIKE(rationale, '[^[:space:]]', 'c'))",
            "AND rationale IS NULL OR REGEXP_LIKE(rationale, '[^[:space:]]', 'c')", $target
        )];

        return $cases;
    }

    #[DataProvider('alteredExpressions')]
    public function test_semantic_changes_fail_closed(string $name, string $expression): void
    {
        $this->assertFalse($this->checkMatches($name, $expression), $expression);
    }

    public function test_expected_checks_match_all_six_foundation_migrations(): void
    {
        $found = [];
        foreach (range(9, 14) as $number) {
            $paths = glob(__DIR__.'/../../database/migrations/'.sprintf('2026_10_10_%06d_*.php', $number));
            $this->assertCount(1, $paths);
            preg_match_all('/CONSTRAINT `([^`]+)` CHECK \((.*?)\),\s*\n/s', file_get_contents($paths[0]), $checks, PREG_SET_ORDER);
            foreach ($checks as $check) {
                $this->assertTrue($this->checkMatches($check[1], $check[2]), $check[1]);
                $found[] = $check[1];
            }
        }
        $expected = array_keys(self::expressions());
        sort($expected);
        sort($found);
        $this->assertSame($expected, $found);
        $this->assertCount(17, $found);
    }
}
