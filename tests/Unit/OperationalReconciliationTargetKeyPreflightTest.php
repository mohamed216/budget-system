<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OperationalReconciliationTargetKeyPreflightTest extends TestCase
{
    private const FOUNDATION = "CASE WHEN `account_id` IS NULL THEN 'excluded' ELSE CONCAT(CAST(`account_id` AS CHAR), ':', `purpose`) END";

    private const MYSQL_84 = <<<'SQL'
(case when (`account_id` is null) then _utf8mb4\'excluded\' else concat(cast(`account_id` as char charset utf8mb4),_utf8mb4\':\',`purpose`) end)
SQL;

    private function metadata(array $overrides = []): object
    {
        return (object) array_replace([
            'DATA_TYPE' => 'varchar', 'COLUMN_TYPE' => 'varchar(37)',
            'CHARACTER_SET_NAME' => 'ascii', 'COLLATION_NAME' => 'ascii_bin',
            'EXTRA' => 'STORED GENERATED', 'GENERATION_EXPRESSION' => self::FOUNDATION,
        ], $overrides);
    }

    private function validate(?object $key): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php';
        (new \ReflectionMethod($migration, 'requireTargetKey'))->invoke($migration, $key);
    }

    public static function equivalentExpressions(): array
    {
        return [
            'foundation' => [self::FOUNDATION],
            'MySQL canonical grouping and introducers' => ["(case when (`account_id` is null) then _utf8mb4'excluded' else concat(cast(`account_id` as char charset utf8mb4),_utf8mb4':',`purpose`) end)"],
            'actual MySQL 8.4 escaped literal serialization' => [self::MYSQL_84],
            'ASCII annotations' => ["(CASE WHEN (account_id IS NULL) THEN _ascii'excluded' ELSE CONCAT(CAST(account_id AS CHAR CHARACTER SET ascii),_ascii':',purpose) END)"],
            'backticked cast charset' => ["(CASE WHEN account_id IS NULL THEN 'excluded' ELSE CONCAT(CAST(account_id AS CHAR CHARSET `utf8mb4`), ':', purpose) END)"],
            'redundant complete-node grouping' => ["((CASE WHEN (((`account_id`)) IS NULL) THEN ('excluded') ELSE ((CONCAT((CAST((account_id) AS CHAR)), (':'), ((purpose))))) END))"],
            'whitespace and keyword case' => ["\n( CaSe\tWhEn (account_id IS NULL) Then 'excluded'\nElse CoNcAt(CaSt(account_id AS CHAR), ':', purpose) End )\n"],
        ];
    }

    #[DataProvider('equivalentExpressions')]
    public function test_only_formatting_equivalent_expressions_are_accepted(string $expression): void
    {
        $this->validate($this->metadata(['GENERATION_EXPRESSION' => $expression]));
        $this->addToAssertionCount(1);
    }

    public static function incompatibleMetadata(): array
    {
        return [
            'wrong data type' => [['DATA_TYPE' => 'char']],
            'wrong length' => [['COLUMN_TYPE' => 'varchar(38)']],
            'wrong charset' => [['CHARACTER_SET_NAME' => 'utf8mb4']],
            'wrong collation' => [['COLLATION_NAME' => 'ascii_general_ci']],
            'virtual' => [['EXTRA' => 'VIRTUAL GENERATED']],
            'ordinary column' => [['EXTRA' => '', 'GENERATION_EXPRESSION' => '']],
            'ordinary default' => [['EXTRA' => 'DEFAULT_GENERATED']],
            'missing expression' => [['GENERATION_EXPRESSION' => '']],
            'wrong null condition' => [['GENERATION_EXPRESSION' => str_replace('IS NULL', 'IS NOT NULL', self::FOUNDATION)]],
            'wrong excluded literal' => [['GENERATION_EXPRESSION' => str_replace("'excluded'", "'different'", self::FOUNDATION)]],
            'case-sensitive excluded literal' => [['GENERATION_EXPRESSION' => str_replace("'excluded'", "'EXCLUDED'", self::FOUNDATION)]],
            'literal whitespace is significant' => [['GENERATION_EXPRESSION' => str_replace("'excluded'", "'ex cluded'", self::FOUNDATION)]],
            'wrong separator' => [['GENERATION_EXPRESSION' => str_replace("':'", "'/'", self::FOUNDATION)]],
            'wrong source column' => [['GENERATION_EXPRESSION' => str_replace('`account_id`', '`user_id`', self::FOUNDATION)]],
            'wrong purpose column' => [['GENERATION_EXPRESSION' => str_replace('`purpose`', '`target_kind`', self::FOUNDATION)]],
            'truncating cast' => [['GENERATION_EXPRESSION' => str_replace('AS CHAR', 'AS CHAR(1)', self::FOUNDATION)]],
            'wrong cast charset' => [['GENERATION_EXPRESSION' => str_replace('AS CHAR', 'AS CHAR CHARSET utf16', self::FOUNDATION)]],
            'reordered arguments' => [['GENERATION_EXPRESSION' => "CASE WHEN account_id IS NULL THEN 'excluded' ELSE CONCAT(purpose, ':', CAST(account_id AS CHAR)) END"]],
            'extra argument' => [['GENERATION_EXPRESSION' => str_replace("':', `purpose`", "':', `purpose`, 'extra'", self::FOUNDATION)]],
            'materially different grouping' => [['GENERATION_EXPRESSION' => "CASE WHEN account_id IS NULL THEN 'excluded' ELSE CONCAT(CAST(account_id AS CHAR), (':', purpose)) END"]],
            'unbalanced grouping' => [['GENERATION_EXPRESSION' => '('.self::FOUNDATION]],
            'trailing expression' => [['GENERATION_EXPRESSION' => self::FOUNDATION." + 'extra'"]],
            'serialized changed literal' => [['GENERATION_EXPRESSION' => str_replace('excluded', 'different', self::MYSQL_84)]],
            'serialized uppercase literal' => [['GENERATION_EXPRESSION' => str_replace('excluded', 'EXCLUDED', self::MYSQL_84)]],
            'serialized changed separator' => [['GENERATION_EXPRESSION' => str_replace("\\':\\'", "\\'/\\'", self::MYSQL_84)]],
            'serialized unmatched quote escaping' => [['GENERATION_EXPRESSION' => str_replace("\\'excluded\\'", "\\'excluded'", self::MYSQL_84)]],
            'backslashes inside literal' => [['GENERATION_EXPRESSION' => str_replace('excluded', 'ex\\cluded', self::MYSQL_84)]],
            'escaped quotes without introducer' => [['GENERATION_EXPRESSION' => str_replace('_utf8mb4', '', self::MYSQL_84)]],
            'serialized wrong condition' => [['GENERATION_EXPRESSION' => str_replace('is null', 'is not null', self::MYSQL_84)]],
            'serialized wrong source' => [['GENERATION_EXPRESSION' => str_replace('`account_id`', '`user_id`', self::MYSQL_84)]],
            'serialized wrong purpose' => [['GENERATION_EXPRESSION' => str_replace('`purpose`', '`target_kind`', self::MYSQL_84)]],
            'serialized truncating cast' => [['GENERATION_EXPRESSION' => str_replace('as char charset', 'as char(1) charset', self::MYSQL_84)]],
            'serialized different cast' => [['GENERATION_EXPRESSION' => str_replace('as char charset utf8mb4', 'as signed', self::MYSQL_84)]],
            'serialized wrong cast charset' => [['GENERATION_EXPRESSION' => str_replace('charset utf8mb4', 'charset utf16', self::MYSQL_84)]],
            'serialized reordered arguments' => [['GENERATION_EXPRESSION' => str_replace("cast(`account_id` as char charset utf8mb4),_utf8mb4\\':\\',`purpose`", "`purpose`,_utf8mb4\\':\\',cast(`account_id` as char charset utf8mb4)", self::MYSQL_84)]],
            'serialized extra argument' => [['GENERATION_EXPRESSION' => str_replace(',`purpose`)', ",`purpose`,'extra')", self::MYSQL_84)]],
            'serialized trailing expression' => [['GENERATION_EXPRESSION' => self::MYSQL_84." + 'extra'"]],
        ];
    }

    #[DataProvider('incompatibleMetadata')]
    public function test_incompatible_generated_columns_fail_closed(array $overrides): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected attribution generated key');
        $this->validate($this->metadata($overrides));
    }

    public function test_missing_column_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validate(null);
    }
}
