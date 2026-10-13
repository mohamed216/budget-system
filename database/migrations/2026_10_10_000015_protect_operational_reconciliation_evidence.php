<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->preflight();
        $definitions = $this->definitions();
        // Never adopt even matching pre-existing triggers. DDL is not transactional:
        // interrupted installation requires inspection and guarded empty rollback.
        $existing = $this->inspect($definitions);
        if ($existing !== []) {
            throw new RuntimeException('Evidence protection trigger names already exist; inspect migration history.');
        }
        foreach (array_keys($this->schema()) as $suffix) {
            if (DB::table('operational_reconciliation_'.$suffix)->exists()) {
                throw new RuntimeException('Refusing to install evidence protections on a nonempty unprotected foundation.');
            }
        }
        foreach ($definitions as $name => [$table, $event, $body]) {
            DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
        }
    }

    public function down(): void
    {
        $definitions = $this->definitions();
        $existing = $this->inspect($definitions);
        foreach (array_keys($this->schema()) as $suffix) {
            $table = 'operational_reconciliation_'.$suffix;
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to remove reconciliation protections while {$table} contains rows.");
            }
        }
        // Inspect the entire set before the first DROP; never remove unknown objects.
        foreach (array_keys($definitions) as $name) {
            if (isset($existing[$name])) {
                DB::unprepared("DROP TRIGGER `{$name}`");
            }
        }
    }

    private function inspect(array $definitions): array
    {
        $tables = array_map(fn ($suffix) => 'operational_reconciliation_'.$suffix, array_keys($this->schema()));
        $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where(fn ($query) => $query->whereIn('EVENT_OBJECT_TABLE', $tables)->orWhereIn('TRIGGER_NAME', array_keys($definitions)))->get();
        $existing = [];
        foreach ($triggers as $trigger) {
            $definition = $definitions[$trigger->TRIGGER_NAME] ?? null;
            if ($definition === null || $trigger->EVENT_OBJECT_TABLE !== $definition[0]
                || $trigger->ACTION_TIMING !== 'BEFORE' || $trigger->EVENT_MANIPULATION !== $definition[1]
                || trim($trigger->ACTION_STATEMENT) !== trim($definition[2])) {
                throw new RuntimeException("Unexpected evidence trigger {$trigger->TRIGGER_NAME}; refusing to create or drop protections.");
            }
            $existing[$trigger->TRIGGER_NAME] = true;
        }

        return $existing;
    }

    private function preflight(): void
    {
        foreach ($this->schema() as $suffix => $expected) {
            $table = 'operational_reconciliation_'.$suffix;
            $metadata = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->first();
            if ($metadata === null || $metadata->ENGINE !== 'InnoDB' || $metadata->TABLE_COLLATION !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException("Missing or incompatible evidence table {$table}.");
            }
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            if ($columns->keys()->all() !== array_keys($expected['columns'])) {
                throw new RuntimeException("Unexpected evidence columns on {$table}.");
            }
            foreach ($expected['columns'] as $name => [$type, $nullable]) {
                if (strtolower($columns[$name]['type']) !== $type || $columns[$name]['nullable'] !== $nullable) {
                    throw new RuntimeException("Incompatible evidence column {$table}.{$name}.");
                }
            }
            $indexes = collect(Schema::getIndexes($table));
            if (! $indexes->contains(fn ($index) => $index['primary'] && $index['columns'] === $expected['primary'])) {
                throw new RuntimeException("Missing evidence primary key on {$table}.");
            }
            foreach ($expected['unique'] as $key) {
                if (! $indexes->contains(fn ($index) => $index['unique'] && $index['columns'] === $key)) {
                    throw new RuntimeException("Missing evidence unique key on {$table}.");
                }
            }
            $foreign = collect(Schema::getForeignKeys($table));
            if ($foreign->count() !== count($expected['foreign'])) {
                throw new RuntimeException("Unexpected evidence foreign keys on {$table}.");
            }
            foreach ($expected['foreign'] as [$key, $parent, $parentKey]) {
                if (! $foreign->contains(fn ($fk) => $fk['columns'] === $key && $fk['foreign_table'] === $parent
                    && $fk['foreign_columns'] === $parentKey && $fk['foreign_schema'] === DB::getDatabaseName()
                    && strtolower($fk['on_delete']) === 'restrict' && strtolower($fk['on_update']) === 'restrict')
                    || ! $indexes->contains(fn ($index) => array_slice($index['columns'], 0, count($key)) === $key)) {
                    throw new RuntimeException("Incompatible owner references on {$table}.");
                }
            }
            $checks = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->where('CONSTRAINT_TYPE', 'CHECK')->get();
            if ($checks->count() !== count($expected['checks'])
                || $checks->contains(fn ($check) => ! in_array($check->CONSTRAINT_NAME, $expected['checks'], true) || $check->ENFORCED !== 'YES')) {
                throw new RuntimeException("Missing or incompatible evidence checks on {$table}.");
            }
            $clauses = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->whereIn('CONSTRAINT_NAME', $expected['checks'])->get()->keyBy('CONSTRAINT_NAME');
            foreach ($expected['checks'] as $name) {
                if (! isset($clauses[$name]) || ! $this->matchesCheckExpression($name, $clauses[$name]->CHECK_CLAUSE)) {
                    throw new RuntimeException("Unexpected evidence CHECK definition {$name} on {$table}.");
                }
            }
        }
        $key = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'operational_reconciliation_attributions')->where('COLUMN_NAME', 'target_key')->first();
        $this->requireTargetKey($key);
        foreach (['journal_entries' => ['id', 'user_id'], 'journal_lines' => ['user_id', 'journal_entry_id', 'id']] as $table => $key) {
            if (! Schema::hasTable($table) || ! collect(Schema::getIndexes($table))->contains(
                fn ($index) => $index['unique'] && $index['columns'] === $key
            )) {
                throw new RuntimeException("Missing owner-safe accounting source key on {$table}.");
            }
        }
        foreach ([
            'journal_entries' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned', 'status' => 'varchar(16)'],
            'journal_lines' => ['id' => 'bigint unsigned', 'user_id' => 'bigint unsigned',
                'journal_entry_id' => 'bigint unsigned', 'chart_account_id' => 'bigint unsigned',
                'debit' => 'decimal(15,2)', 'credit' => 'decimal(15,2)'],
        ] as $table => $expected) {
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($expected as $name => $type) {
                if (! isset($columns[$name]) || strtolower($columns[$name]['type']) !== $type || $columns[$name]['nullable']) {
                    throw new RuntimeException("Incompatible accounting source column {$table}.{$name}.");
                }
            }
        }
    }

    private function checkExpressions(): array
    {
        return [
            'orr_strategy_check' => "CAST(historical_transaction_strategy AS BINARY) IN ('historical_backfill', 'balance_only')",
            'orr_currency_check' => "REGEXP_LIKE(accounting_currency, '^[A-Z]{3}$', 'c')",
            'orr_reference_check' => "CHAR_LENGTH(reference) = 36 AND REGEXP_LIKE(reference, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')",
            'orr_completion_pair_check' => '(reviewed_by IS NULL AND completed_at IS NULL) OR (reviewed_by IS NOT NULL AND completed_at IS NOT NULL)',
            'orr_completion_time_check' => 'completed_at IS NULL OR completed_at >= created_at',
            'orr_disclosure_check' => <<<'SQL'
(CAST(historical_transaction_strategy AS BINARY) = 'historical_backfill' AND balance_only_disclosure_code IS NULL AND balance_only_disclosure_text IS NULL)
OR (CAST(historical_transaction_strategy AS BINARY) = 'balance_only' AND balance_only_disclosure_code IS NOT NULL AND CAST(balance_only_disclosure_code AS BINARY) = 'balance_only_legacy_history_v1' AND balance_only_disclosure_text IS NOT NULL AND REGEXP_LIKE(balance_only_disclosure_text, '[^[:space:]]', 'c'))
SQL,
            'orc_type_check' => "CAST(type_snapshot AS BINARY) IN ('asset', 'liability', 'equity', 'revenue', 'expense')",
            'orc_role_check' => "CAST(cash_role_snapshot AS BINARY) IN ('non_cash', 'cash', 'cash_equivalent')",
            'orc_cash_asset_check' => "CAST(cash_role_snapshot AS BINARY) = 'non_cash' OR CAST(type_snapshot AS BINARY) = 'asset'",
            'orc_active_check' => 'is_active_snapshot IN (0, 1)',
            'ora_account_check' => 'account_id > 0',
            'ora_currency_check' => "REGEXP_LIKE(currency_snapshot, '^[A-Z]{3}$', 'c')",
            'ora_rationale_check' => "rationale IS NULL OR REGEXP_LIKE(rationale, '[^[:space:]]', 'c')",
            'orcat_type_check' => "CAST(category_type_snapshot AS BINARY) IN ('income', 'expense')",
            'orcat_activity_check' => "CAST(cash_flow_activity AS BINARY) IN ('operating', 'investing', 'financing')",
            'orat_amount_check' => 'amount > 0',
            'orat_target_check' => <<<'SQL'
(CAST(target_kind AS BINARY) = 'account' AND account_id IS NOT NULL AND account_id > 0
    AND purpose IS NOT NULL AND CAST(purpose AS BINARY) IN ('opening_balance', 'existing_history')
    AND (rationale IS NULL OR REGEXP_LIKE(rationale, '[^[:space:]]', 'c')))
OR (CAST(target_kind AS BINARY) = 'excluded' AND account_id IS NULL AND purpose IS NULL
    AND rationale IS NOT NULL AND REGEXP_LIKE(rationale, '[^[:space:]]', 'c'))
SQL,
        ];
    }

    private function matchesCheckExpression(string $name, string $expression): bool
    {
        $expected = $this->checkExpressions()[$name] ?? null;
        if ($expected === null) {
            return false;
        }
        try {
            return $this->parseCheckExpression($expression) === $this->parseCheckExpression($expected);
        } catch (RuntimeException $exception) {
            return false;
        }
    }

    private function parseCheckExpression(string $expression): array
    {
        // Deliberately limited SQL grammar, not text stripping or SQL execution.
        // Literal contents remain case/whitespace sensitive. Only ASCII-compatible
        // introducers on these ASCII literals and MySQL's binary CAST spelling
        // are canonicalized. Grouping is parsed with AND/OR precedence intact.
        $pattern = <<<'REGEX'
~\G\s*(?:(?<introducer>_(?:utf8mb4|utf8mb3|utf8|ascii|binary)\s*)?'(?<literal>[^'\\]*)'|(?<serialized_introducer>_(?:utf8mb4|utf8mb3|utf8|ascii|binary)\s*)\\'(?<serialized>[^'\\]*)\\'|`(?<quoted>[a-z_][a-z_0-9]*)`|(?<word>[a-z_][a-z_0-9]*)|(?<number>[0-9]+(?:\.[0-9]+)?)|(?<symbol>>=|[(),=>]))~i
REGEX;
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (trim(substr($expression, $offset)) === '') {
                break;
            }
            if (preg_match($pattern, $expression, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                throw new RuntimeException('Unsupported CHECK syntax.');
            }
            $offset += strlen($match[0]);
            if ($match['literal'] !== null || $match['serialized'] !== null) {
                $introducer = strtolower(trim($match['introducer'] ?? $match['serialized_introducer'] ?? ''));
                $tokens[] = [$introducer === '_binary' ? 'binary_literal' : 'literal', $match['literal'] ?? $match['serialized']];
            } elseif ($match['number'] !== null) {
                [$integer, $fraction] = array_pad(explode('.', $match['number'], 2), 2, '');
                $integer = ltrim($integer, '0') ?: '0';
                $fraction = rtrim($fraction, '0');
                $tokens[] = ['number', $integer.($fraction === '' ? '' : '.'.$fraction)];
            } else {
                $tokens[] = ['syntax', strtolower($match['quoted'] ?? $match['word'] ?? $match['symbol'])];
            }
        }
        $position = 0;
        // Use a reference capture: each read must see the current token position.
        $peek = function () use ($tokens, &$position): ?array {
            return $tokens[$position] ?? null;
        };
        $take = function (string $value) use ($tokens, &$position): void {
            if (($tokens[$position] ?? null) !== ['syntax', $value]) {
                throw new RuntimeException('Unexpected CHECK token.');
            }
            $position++;
        };
        $parse = function (int $minimum = 0, int $depth = 0) use (&$parse, &$position, $peek, $take): array {
            if ($depth > 64 || $peek() === null) {
                throw new RuntimeException('Invalid CHECK expression.');
            }
            $token = $peek();
            $position++;
            if ($token === ['syntax', '(']) {
                $node = $parse(0, $depth + 1);
                $take(')');
            } elseif ($token === ['syntax', 'cast']) {
                $take('(');
                $operand = $parse(4, $depth + 1);
                $take('as');
                if ($peek() === ['syntax', 'binary']) {
                    $take('binary');
                } else {
                    $take('char');
                    if ($peek() === ['syntax', 'charset']) {
                        $take('charset');
                    } else {
                        $take('character');
                        $take('set');
                    }
                    $take('binary');
                }
                $take(')');
                $node = ['binary_cast', $operand];
            } elseif (in_array($token, [['syntax', 'regexp_like'], ['syntax', 'char_length']], true)) {
                $take('(');
                $arguments = [$parse(0, $depth + 1)];
                while ($peek() === ['syntax', ',']) {
                    $take(',');
                    $arguments[] = $parse(0, $depth + 1);
                }
                $take(')');
                $node = [$token[1], $arguments];
            } else {
                $node = $token;
            }
            while (($next = $peek()) !== null && $next[0] === 'syntax') {
                $operator = $next[1];
                $precedence = match ($operator) {
                    'or' => 1, 'and' => 2, 'is', 'in', '=', '>', '>=' => 3, default => -1,
                };
                if ($precedence < $minimum || $precedence < 0) {
                    break;
                }
                $position++;
                if ($operator === 'is') {
                    $negated = $peek() === ['syntax', 'not'];
                    if ($negated) {
                        $take('not');
                    }
                    $take('null');
                    $node = ['is_null', $node, $negated];
                } elseif ($operator === 'in') {
                    $take('(');
                    $values = [$parse(4, $depth + 1)];
                    while ($peek() === ['syntax', ',']) {
                        $take(',');
                        $values[] = $parse(4, $depth + 1);
                    }
                    $take(')');
                    if ($node[0] === 'binary_cast') {
                        $values = array_map(fn ($value) => $value[0] === 'binary_literal' ? ['literal', $value[1]] : $value, $values);
                    }
                    $node = ['in', $node, $values];
                } else {
                    $right = $parse($precedence + 1, $depth + 1);
                    // MySQL can serialize coerced comparison literals as
                    // _binary. Equivalent ONLY with an explicitly binary left
                    // operand; never strip binary introducers in regex calls.
                    if ($node[0] === 'binary_cast' && $right[0] === 'binary_literal') {
                        $right = ['literal', $right[1]];
                    }
                    if (in_array($operator, ['and', 'or'], true)) {
                        // Associative grouping only; never reorder operands or
                        // flatten across different operators (NULL logic matters).
                        $leftNodes = $node[0] === $operator ? array_slice($node, 1) : [$node];
                        $rightNodes = $right[0] === $operator ? array_slice($right, 1) : [$right];
                        $node = [$operator, ...$leftNodes, ...$rightNodes];
                    } else {
                        $node = [$operator, $node, $right];
                    }
                }
            }

            return $node;
        };
        $result = $parse();
        if ($position !== count($tokens)) {
            throw new RuntimeException('Trailing CHECK expression.');
        }

        return $result;
    }

    private function requireTargetKey(?object $key): void
    {
        if ($key === null || $key->DATA_TYPE !== 'varchar' || $key->COLUMN_TYPE !== 'varchar(37)'
            || $key->CHARACTER_SET_NAME !== 'ascii' || $key->COLLATION_NAME !== 'ascii_bin'
            || strtoupper(trim($key->EXTRA)) !== 'STORED GENERATED'
            || ! $this->matchesTargetKeyExpression($key->GENERATION_EXPRESSION)) {
            throw new RuntimeException('Unexpected attribution generated key; refusing evidence protection.');
        }
    }

    private function matchesTargetKeyExpression(string $expression): bool
    {
        // A grammar for this one expression, not a general SQL simplifier. MySQL
        // wraps CASE/subexpressions and prints literal introducers / CHAR charsets.
        // Only grouping around complete nodes is optional; function argument
        // boundaries, column identities and case-sensitive literal values remain.
        $pattern = <<<'REGEX'
~\A\s*(?&key)\s*\z
(?(DEFINE)
    (?<account> (?: account_id\b | `account_id` | \(\s*(?&account)\s*\) ) )
    (?<purpose> (?: purpose\b | `purpose` | \(\s*(?&purpose)\s*\) ) )
    (?<charset> (?: ascii | utf8mb4 | utf8mb3 | utf8 )\b )
    (?<excluded>
        (?: (?:_(?&charset)\s*)? (?-i:'excluded')
          | _utf8mb4\s*(?-i:\\'excluded\\')
          | \(\s*(?&excluded)\s*\)
        )
    )
    (?<separator>
        (?: (?:_(?&charset)\s*)? ':'
          | _utf8mb4\s*\\':\\'
          | \(\s*(?&separator)\s*\)
        )
    )
    (?<condition>
        (?: (?&account)\s*IS\b\s+NULL\b | \(\s*(?&condition)\s*\) )
    )
    (?<charcast>
        (?: CAST\b\s*\(\s*(?&account)\s*AS\b\s+CHAR\b
            (?: \s+(?: CHARSET\b | CHARACTER\b\s+SET\b )\s+(?: (?&charset) | `(?&charset)` ) )?
            \s*\)
          | \(\s*(?&charcast)\s*\)
        )
    )
    (?<concatenation>
        (?: CONCAT\b\s*\(\s*(?&charcast)\s*,\s*(?&separator)\s*,\s*(?&purpose)\s*\)
          | \(\s*(?&concatenation)\s*\)
        )
    )
    (?<key>
        (?: CASE\b\s+WHEN\b\s*(?&condition)\s*THEN\b\s*(?&excluded)
            \s*ELSE\b\s*(?&concatenation)\s*END\b
          | \(\s*(?&key)\s*\)
        )
    )
)
~ix
REGEX;

        // Invalid syntax or a PCRE recursion/resource error also fails closed.
        return preg_match($pattern, $expression) === 1;
    }

    private function schema(): array
    {
        return [
            'reviews' => [
                'columns' => [
                    'id' => ['bigint unsigned', false],
                    'user_id' => ['bigint unsigned', false],
                    'reference' => ['varchar(255)', false],
                    'historical_transaction_strategy' => ['varchar(32)', false],
                    'accounting_currency' => ['char(3)', false],
                    'transaction_high_water_id' => ['bigint unsigned', false],
                    'scope_reviewed_at' => ['timestamp', false],
                    'reviewed_by' => ['bigint unsigned', true],
                    'completed_at' => ['datetime(6)', true],
                    'supersedes_review_id' => ['bigint unsigned', true],
                    'balance_only_disclosure_code' => ['varchar(32)', true],
                    'balance_only_disclosure_text' => ['text', true],
                    'created_at' => ['datetime(6)', false],
                ],
                'primary' => ['id'],
                'unique' => [['id', 'user_id'], ['user_id', 'reference']],
                'checks' => ['orr_strategy_check', 'orr_currency_check', 'orr_reference_check', 'orr_completion_pair_check', 'orr_completion_time_check', 'orr_disclosure_check'],
                'foreign' => [
                    [['user_id'], 'users', ['id']],
                    [['reviewed_by'], 'users', ['id']],
                    [['supersedes_review_id', 'user_id'], 'operational_reconciliation_reviews', ['id', 'user_id']],
                ],
            ],
            'charts' => [
                'columns' => [
                    'review_id' => ['bigint unsigned', false],
                    'user_id' => ['bigint unsigned', false],
                    'chart_account_id' => ['bigint unsigned', false],
                    'type_snapshot' => ['varchar(16)', false],
                    'cash_role_snapshot' => ['varchar(16)', false],
                    'is_active_snapshot' => ['tinyint(1)', false],
                ],
                'primary' => ['review_id', 'chart_account_id'],
                'unique' => [['review_id', 'user_id', 'chart_account_id']],
                'checks' => ['orc_type_check', 'orc_role_check', 'orc_cash_asset_check', 'orc_active_check'],
                'foreign' => [
                    [['review_id', 'user_id'], 'operational_reconciliation_reviews', ['id', 'user_id']],
                    [['chart_account_id', 'user_id'], 'chart_of_accounts', ['id', 'user_id']],
                ],
            ],
            'accounts' => [
                'columns' => [
                    'review_id' => ['bigint unsigned', false],
                    'user_id' => ['bigint unsigned', false],
                    'account_id' => ['bigint unsigned', false],
                    'chart_account_id' => ['bigint unsigned', false],
                    'stored_balance_snapshot' => ['decimal(15,2)', false],
                    'currency_snapshot' => ['char(3)', false],
                    'rationale' => ['text', true],
                ],
                'primary' => ['review_id', 'account_id'],
                'unique' => [['review_id', 'user_id', 'account_id']],
                'checks' => ['ora_account_check', 'ora_currency_check', 'ora_rationale_check'],
                'foreign' => [
                    [['review_id', 'user_id'], 'operational_reconciliation_reviews', ['id', 'user_id']],
                    [['account_id', 'user_id'], 'accounts', ['id', 'user_id']],
                    [['review_id', 'user_id', 'chart_account_id'], 'operational_reconciliation_charts', ['review_id', 'user_id', 'chart_account_id']],
                ],
            ],
            'categories' => [
                'columns' => [
                    'review_id' => ['bigint unsigned', false],
                    'user_id' => ['bigint unsigned', false],
                    'category_id' => ['bigint unsigned', false],
                    'category_type_snapshot' => ['varchar(16)', false],
                    'chart_account_id' => ['bigint unsigned', false],
                    'cash_flow_activity' => ['varchar(16)', false],
                ],
                'primary' => ['review_id', 'category_id'],
                'unique' => [],
                'checks' => ['orcat_type_check', 'orcat_activity_check'],
                'foreign' => [
                    [['review_id', 'user_id'], 'operational_reconciliation_reviews', ['id', 'user_id']],
                    [['category_id', 'user_id'], 'categories', ['id', 'user_id']],
                    [['review_id', 'user_id', 'chart_account_id'], 'operational_reconciliation_charts', ['review_id', 'user_id', 'chart_account_id']],
                ],
            ],
            'journals' => [
                'columns' => [
                    'review_id' => ['bigint unsigned', false],
                    'user_id' => ['bigint unsigned', false],
                    'journal_entry_id' => ['bigint unsigned', false],
                ],
                'primary' => ['review_id', 'journal_entry_id'],
                'unique' => [['review_id', 'user_id', 'journal_entry_id']],
                'checks' => [],
                'foreign' => [
                    [['review_id', 'user_id'], 'operational_reconciliation_reviews', ['id', 'user_id']],
                    [['journal_entry_id', 'user_id'], 'journal_entries', ['id', 'user_id']],
                ],
            ],
            'attributions' => [
                'columns' => [
                    'id' => ['bigint unsigned', false],
                    'review_id' => ['bigint unsigned', false],
                    'user_id' => ['bigint unsigned', false],
                    'journal_entry_id' => ['bigint unsigned', false],
                    'journal_line_id' => ['bigint unsigned', false],
                    'target_kind' => ['varchar(16)', false],
                    'account_id' => ['bigint unsigned', true],
                    'purpose' => ['varchar(32)', true],
                    'amount' => ['decimal(15,2)', false],
                    'rationale' => ['text', true],
                    'target_key' => ['varchar(37)', true],
                ],
                'primary' => ['id'],
                'unique' => [['review_id', 'journal_line_id', 'target_key']],
                'checks' => ['orat_amount_check', 'orat_target_check'],
                'foreign' => [
                    [['review_id', 'user_id'], 'operational_reconciliation_reviews', ['id', 'user_id']],
                    [['review_id', 'user_id', 'journal_entry_id'], 'operational_reconciliation_journals', ['review_id', 'user_id', 'journal_entry_id']],
                    [['user_id', 'journal_entry_id', 'journal_line_id'], 'journal_lines', ['user_id', 'journal_entry_id', 'id']],
                    [['review_id', 'user_id', 'account_id'], 'operational_reconciliation_accounts', ['review_id', 'user_id', 'account_id']],
                ],
            ],
        ];
    }

    // MySQL 8.4 trigger prelocking does not reliably preserve NOWAIT. These
    // guards use waiting CURRENT reads, live/source dependencies first and the
    // review parent last. The future sealer must prelock the same sources and
    // evidence dependencies in stable ID order BEFORE its exclusive review lock.
    // DML can already hold its target row; application mutations must prelock
    // owner/reviewer and all affected sources before issuing DML. Restrictive
    // implicit FK checks may wait; this is NOT a database nonwaiting guarantee.
    private function definitions(): array
    {
        return [
            'orr_header_bi' => ['operational_reconciliation_reviews', 'INSERT', <<<'SQL'
BEGIN
    DECLARE predecessor_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE predecessor_completed DATETIME(6) DEFAULT NULL;
    IF NEW.reviewed_by IS NOT NULL OR NEW.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation reviews must be inserted unsealed';
    END IF;
    IF NEW.supersedes_review_id IS NOT NULL THEN
        IF NEW.supersedes_review_id = NEW.id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A reconciliation review cannot supersede itself';
        END IF;
        SELECT id, completed_at INTO predecessor_id, predecessor_completed
            FROM operational_reconciliation_reviews
            WHERE id = NEW.supersedes_review_id AND user_id = NEW.user_id FOR SHARE;
        IF predecessor_id IS NULL OR predecessor_completed IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supersession requires an owned completed predecessor';
        END IF;
    END IF;
END
SQL],
            'orr_header_bu' => ['operational_reconciliation_reviews', 'UPDATE', <<<'SQL'
BEGIN
    IF OLD.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation header cannot be updated';
    END IF;
    IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.user_id <=> OLD.user_id)
        OR NOT (CAST(NEW.reference AS BINARY) <=> CAST(OLD.reference AS BINARY))
        OR NOT (NEW.created_at <=> OLD.created_at)
        OR NOT (NEW.supersedes_review_id <=> OLD.supersedes_review_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation header identity cannot change';
    END IF;
    IF NEW.reviewed_by IS NOT NULL OR NEW.completed_at IS NOT NULL THEN
        IF OLD.reviewed_by IS NOT NULL OR NEW.reviewed_by IS NULL OR NEW.completed_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealing requires reviewer and completion time together';
        END IF;
        IF NOT (CAST(NEW.historical_transaction_strategy AS BINARY) <=> CAST(OLD.historical_transaction_strategy AS BINARY))
            OR NOT (CAST(NEW.accounting_currency AS BINARY) <=> CAST(OLD.accounting_currency AS BINARY))
            OR NOT (NEW.transaction_high_water_id <=> OLD.transaction_high_water_id)
            OR NOT (NEW.scope_reviewed_at <=> OLD.scope_reviewed_at)
            OR NOT (CAST(NEW.balance_only_disclosure_code AS BINARY) <=> CAST(OLD.balance_only_disclosure_code AS BINARY))
            OR NOT (CAST(NEW.balance_only_disclosure_text AS BINARY) <=> CAST(OLD.balance_only_disclosure_text AS BINARY)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealing may change only reviewer and completion time';
        END IF;
    END IF;
    -- Financial reconciliation and reviewer authorization belong to the later action.
END
SQL],
            'orr_header_bd' => ['operational_reconciliation_reviews', 'DELETE', <<<'SQL'
BEGIN
    IF OLD.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation header cannot be deleted';
    END IF;
END
SQL],
            'orr_charts_bi' => ['operational_reconciliation_charts', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    SELECT id INTO live_id FROM chart_of_accounts
        WHERE id = NEW.chart_account_id AND user_id = NEW.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = NEW.review_id AND user_id = NEW.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_charts_bu' => ['operational_reconciliation_charts', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE dependent_id BIGINT UNSIGNED DEFAULT NULL;
    IF NOT (NEW.review_id <=> OLD.review_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.chart_account_id <=> OLD.chart_account_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence owner, review and source identity cannot change';
    END IF;
    SELECT id INTO live_id FROM chart_of_accounts
        WHERE id = NEW.chart_account_id AND user_id = NEW.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;
    IF NOT (CAST(NEW.type_snapshot AS BINARY) <=> CAST(OLD.type_snapshot AS BINARY)) THEN
        SELECT account_id INTO dependent_id FROM operational_reconciliation_accounts
            WHERE review_id = OLD.review_id AND user_id = OLD.user_id
                AND chart_account_id = OLD.chart_account_id ORDER BY account_id LIMIT 1 FOR SHARE;
        IF dependent_id IS NOT NULL AND CAST(NEW.type_snapshot AS BINARY) <> 'asset' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chart edit would invalidate account evidence';
        END IF;
        SET dependent_id = NULL;
        SELECT category_id INTO dependent_id FROM operational_reconciliation_categories
            WHERE review_id = OLD.review_id AND user_id = OLD.user_id AND chart_account_id = OLD.chart_account_id
                AND ((CAST(category_type_snapshot AS BINARY) = 'income' AND CAST(NEW.type_snapshot AS BINARY) <> 'revenue')
                    OR (CAST(category_type_snapshot AS BINARY) = 'expense' AND CAST(NEW.type_snapshot AS BINARY) <> 'expense'))
            ORDER BY category_id LIMIT 1 FOR SHARE;
        IF dependent_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chart edit would invalidate category evidence';
        END IF;
        SET dependent_id = NULL;
        SELECT a.id INTO dependent_id FROM operational_reconciliation_attributions a
            JOIN journal_lines l ON l.user_id = a.user_id AND l.journal_entry_id = a.journal_entry_id AND l.id = a.journal_line_id
            WHERE a.review_id = OLD.review_id AND a.user_id = OLD.user_id AND l.chart_account_id = OLD.chart_account_id
            ORDER BY a.id LIMIT 1 FOR SHARE;
        IF dependent_id IS NOT NULL AND CAST(NEW.type_snapshot AS BINARY) <> 'asset' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Chart edit would invalidate attribution evidence';
        END IF;
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_charts_bd' => ['operational_reconciliation_charts', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    SELECT id INTO live_id FROM chart_of_accounts
        WHERE id = OLD.chart_account_id AND user_id = OLD.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_accounts_bi' => ['operational_reconciliation_accounts', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    SELECT id INTO live_id FROM accounts
        WHERE id = NEW.account_id AND user_id = NEW.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF NEW.chart_account_id IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = NEW.chart_account_id AND user_id = NEW.user_id FOR SHARE;
        END IF;
    END;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF NEW.chart_account_id IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = NEW.chart_account_id AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND chart_account_id = NEW.chart_account_id FOR SHARE;
    IF target_type IS NULL OR CAST(target_type AS BINARY) <> 'asset' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account evidence requires a reviewed asset chart';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = NEW.review_id AND user_id = NEW.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_accounts_bu' => ['operational_reconciliation_accounts', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    DECLARE dependent_id BIGINT UNSIGNED DEFAULT NULL;
    IF NOT (NEW.review_id <=> OLD.review_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.account_id <=> OLD.account_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence owner, review and source identity cannot change';
    END IF;
    SELECT id INTO live_id FROM accounts
        WHERE id = NEW.account_id AND user_id = NEW.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        SET last_source = GREATEST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        IF first_source IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = first_source AND user_id = NEW.user_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT id INTO locked_source FROM chart_of_accounts
                    WHERE id = last_source AND user_id = NEW.user_id FOR SHARE;
            END IF;
        END IF;
    END;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        SET last_source = GREATEST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        IF first_source IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = first_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                    WHERE chart_account_id = last_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            END IF;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND chart_account_id = NEW.chart_account_id FOR SHARE;
    IF target_type IS NULL OR CAST(target_type AS BINARY) <> 'asset' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account evidence requires a reviewed asset chart';
    END IF;
    IF NEW.chart_account_id <> OLD.chart_account_id THEN
        SELECT a.id INTO dependent_id FROM operational_reconciliation_attributions a
            JOIN journal_lines l ON l.user_id = a.user_id AND l.journal_entry_id = a.journal_entry_id AND l.id = a.journal_line_id
            WHERE a.review_id = OLD.review_id AND a.user_id = OLD.user_id AND a.account_id = OLD.account_id
                AND l.chart_account_id <> NEW.chart_account_id ORDER BY a.id LIMIT 1 FOR SHARE;
        IF dependent_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account chart edit would invalidate attribution evidence';
        END IF;
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_accounts_bd' => ['operational_reconciliation_accounts', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    SELECT id INTO live_id FROM accounts
        WHERE id = OLD.account_id AND user_id = OLD.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF OLD.chart_account_id IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = OLD.chart_account_id AND user_id = OLD.user_id FOR SHARE;
        END IF;
    END;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF OLD.chart_account_id IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = OLD.chart_account_id AND user_id = OLD.user_id AND review_id = OLD.review_id FOR SHARE;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = OLD.review_id AND user_id = OLD.user_id
            AND chart_account_id = OLD.chart_account_id FOR SHARE;
    IF target_type IS NULL OR CAST(target_type AS BINARY) <> 'asset' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Account evidence requires a reviewed asset chart';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_categories_bi' => ['operational_reconciliation_categories', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    SELECT id INTO live_id FROM categories
        WHERE id = NEW.category_id AND user_id = NEW.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF NEW.chart_account_id IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = NEW.chart_account_id AND user_id = NEW.user_id FOR SHARE;
        END IF;
    END;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF NEW.chart_account_id IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = NEW.chart_account_id AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND chart_account_id = NEW.chart_account_id FOR SHARE;
    IF target_type IS NULL
        OR (CAST(NEW.category_type_snapshot AS BINARY) = 'income' AND CAST(target_type AS BINARY) <> 'revenue')
        OR (CAST(NEW.category_type_snapshot AS BINARY) = 'expense' AND CAST(target_type AS BINARY) <> 'expense') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Category evidence requires a compatible reviewed chart';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = NEW.review_id AND user_id = NEW.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_categories_bu' => ['operational_reconciliation_categories', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    IF NOT (NEW.review_id <=> OLD.review_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.category_id <=> OLD.category_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence owner, review and source identity cannot change';
    END IF;
    SELECT id INTO live_id FROM categories
        WHERE id = NEW.category_id AND user_id = NEW.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        SET last_source = GREATEST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        IF first_source IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = first_source AND user_id = NEW.user_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT id INTO locked_source FROM chart_of_accounts
                    WHERE id = last_source AND user_id = NEW.user_id FOR SHARE;
            END IF;
        END IF;
    END;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        SET last_source = GREATEST(COALESCE(NEW.chart_account_id, OLD.chart_account_id), COALESCE(OLD.chart_account_id, NEW.chart_account_id));
        IF first_source IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = first_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                    WHERE chart_account_id = last_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            END IF;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND chart_account_id = NEW.chart_account_id FOR SHARE;
    IF target_type IS NULL
        OR (CAST(NEW.category_type_snapshot AS BINARY) = 'income' AND CAST(target_type AS BINARY) <> 'revenue')
        OR (CAST(NEW.category_type_snapshot AS BINARY) = 'expense' AND CAST(target_type AS BINARY) <> 'expense') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Category evidence requires a compatible reviewed chart';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_categories_bd' => ['operational_reconciliation_categories', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE live_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    SELECT id INTO live_id FROM categories
        WHERE id = OLD.category_id AND user_id = OLD.user_id FOR SHARE;
    IF live_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned operational or chart source';
    END IF;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF OLD.chart_account_id IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = OLD.chart_account_id AND user_id = OLD.user_id FOR SHARE;
        END IF;
    END;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF OLD.chart_account_id IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = OLD.chart_account_id AND user_id = OLD.user_id AND review_id = OLD.review_id FOR SHARE;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = OLD.review_id AND user_id = OLD.user_id
            AND chart_account_id = OLD.chart_account_id FOR SHARE;
    IF target_type IS NULL
        OR (CAST(OLD.category_type_snapshot AS BINARY) = 'income' AND CAST(target_type AS BINARY) <> 'revenue')
        OR (CAST(OLD.category_type_snapshot AS BINARY) = 'expense' AND CAST(target_type AS BINARY) <> 'expense') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Category evidence requires a compatible reviewed chart';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_journals_bi' => ['operational_reconciliation_journals', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    SELECT status INTO journal_status FROM journal_entries
        WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR SHARE;
    IF journal_status IS NULL OR CAST(journal_status AS BINARY) <> 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Review membership requires an owned posted journal';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = NEW.review_id AND user_id = NEW.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_journals_bu' => ['operational_reconciliation_journals', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    IF NOT (NEW.review_id <=> OLD.review_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.journal_entry_id <=> OLD.journal_entry_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence owner, review and source identity cannot change';
    END IF;

    SELECT status INTO journal_status FROM journal_entries
        WHERE id = OLD.journal_entry_id AND user_id = OLD.user_id FOR SHARE;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal membership cannot be updated';
END
SQL],
            'orr_journals_bd' => ['operational_reconciliation_journals', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    SELECT status INTO journal_status FROM journal_entries
        WHERE id = OLD.journal_entry_id AND user_id = OLD.user_id FOR SHARE;
    IF journal_status IS NULL OR CAST(journal_status AS BINARY) <> 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Review membership requires an owned posted journal';
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_attributions_bi' => ['operational_reconciliation_attributions', 'INSERT', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    DECLARE membership_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    DECLARE line_chart BIGINT UNSIGNED DEFAULT NULL;
    DECLARE line_debit DECIMAL(15,2) DEFAULT NULL;
    DECLARE line_credit DECIMAL(15,2) DEFAULT NULL;
    DECLARE account_chart BIGINT UNSIGNED DEFAULT NULL;
    SELECT status INTO journal_status FROM journal_entries
        WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR SHARE;
    IF journal_status IS NULL OR CAST(journal_status AS BINARY) <> 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires a posted journal';
    END IF;
    SELECT chart_account_id, debit, credit INTO line_chart, line_debit, line_credit FROM journal_lines
        WHERE user_id = NEW.user_id AND journal_entry_id = NEW.journal_entry_id
            AND id = NEW.journal_line_id FOR SHARE;
    IF line_chart IS NULL OR line_debit IS NULL OR line_credit IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution line must belong to its owned journal';
    END IF;
    SELECT journal_entry_id INTO membership_id FROM operational_reconciliation_journals
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND journal_entry_id = NEW.journal_entry_id FOR SHARE;
    IF membership_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires owned review journal membership';
    END IF;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF NEW.account_id IS NOT NULL THEN
            SELECT id INTO locked_source FROM accounts
                WHERE id = NEW.account_id AND user_id = NEW.user_id FOR SHARE;
        END IF;
    END;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF NEW.account_id IS NOT NULL THEN
            SELECT account_id INTO locked_source FROM operational_reconciliation_accounts
                WHERE account_id = NEW.account_id AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
        END IF;
    END;
    IF CAST(NEW.target_kind AS BINARY) = 'account' THEN
        SELECT chart_account_id INTO account_chart FROM operational_reconciliation_accounts
            WHERE review_id = NEW.review_id AND user_id = NEW.user_id
                AND account_id = NEW.account_id FOR SHARE;
    END IF;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        SET last_source = GREATEST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        IF first_source IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = first_source AND user_id = NEW.user_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT id INTO locked_source FROM chart_of_accounts
                    WHERE id = last_source AND user_id = NEW.user_id FOR SHARE;
            END IF;
        END IF;
    END;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        SET last_source = GREATEST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        IF first_source IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = first_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                    WHERE chart_account_id = last_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            END IF;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND chart_account_id = line_chart FOR SHARE;
    IF target_type IS NULL OR CAST(target_type AS BINARY) <> 'asset' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires a represented asset line';
    END IF;
    IF NOT ((line_debit > 0 AND line_credit = 0) OR (line_credit > 0 AND line_debit = 0))
        OR NEW.amount > GREATEST(line_debit, line_credit) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution amount exceeds its positive source line';
    END IF;
    IF CAST(NEW.target_kind AS BINARY) = 'account' THEN
        SELECT chart_account_id INTO account_chart FROM operational_reconciliation_accounts
            WHERE review_id = NEW.review_id AND user_id = NEW.user_id
                AND account_id = NEW.account_id FOR SHARE;
        IF account_chart IS NULL OR account_chart <> line_chart THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution account must use the source line reviewed chart';
        END IF;
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = NEW.review_id AND user_id = NEW.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_attributions_bu' => ['operational_reconciliation_attributions', 'UPDATE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    DECLARE membership_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    DECLARE line_chart BIGINT UNSIGNED DEFAULT NULL;
    DECLARE line_debit DECIMAL(15,2) DEFAULT NULL;
    DECLARE line_credit DECIMAL(15,2) DEFAULT NULL;
    DECLARE account_chart BIGINT UNSIGNED DEFAULT NULL;
    IF NOT (NEW.review_id <=> OLD.review_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (NEW.id <=> OLD.id) OR NOT (NEW.journal_entry_id <=> OLD.journal_entry_id) OR NOT (NEW.journal_line_id <=> OLD.journal_line_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence owner, review and source identity cannot change';
    END IF;
    SELECT status INTO journal_status FROM journal_entries
        WHERE id = NEW.journal_entry_id AND user_id = NEW.user_id FOR SHARE;
    IF journal_status IS NULL OR CAST(journal_status AS BINARY) <> 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires a posted journal';
    END IF;
    SELECT chart_account_id, debit, credit INTO line_chart, line_debit, line_credit FROM journal_lines
        WHERE user_id = NEW.user_id AND journal_entry_id = NEW.journal_entry_id
            AND id = NEW.journal_line_id FOR SHARE;
    IF line_chart IS NULL OR line_debit IS NULL OR line_credit IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution line must belong to its owned journal';
    END IF;
    SELECT journal_entry_id INTO membership_id FROM operational_reconciliation_journals
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND journal_entry_id = NEW.journal_entry_id FOR SHARE;
    IF membership_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires owned review journal membership';
    END IF;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(NEW.account_id, OLD.account_id), COALESCE(OLD.account_id, NEW.account_id));
        SET last_source = GREATEST(COALESCE(NEW.account_id, OLD.account_id), COALESCE(OLD.account_id, NEW.account_id));
        IF first_source IS NOT NULL THEN
            SELECT id INTO locked_source FROM accounts
                WHERE id = first_source AND user_id = NEW.user_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT id INTO locked_source FROM accounts
                    WHERE id = last_source AND user_id = NEW.user_id FOR SHARE;
            END IF;
        END IF;
    END;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(NEW.account_id, OLD.account_id), COALESCE(OLD.account_id, NEW.account_id));
        SET last_source = GREATEST(COALESCE(NEW.account_id, OLD.account_id), COALESCE(OLD.account_id, NEW.account_id));
        IF first_source IS NOT NULL THEN
            SELECT account_id INTO locked_source FROM operational_reconciliation_accounts
                WHERE account_id = first_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT account_id INTO locked_source FROM operational_reconciliation_accounts
                    WHERE account_id = last_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            END IF;
        END IF;
    END;
    IF CAST(NEW.target_kind AS BINARY) = 'account' THEN
        SELECT chart_account_id INTO account_chart FROM operational_reconciliation_accounts
            WHERE review_id = NEW.review_id AND user_id = NEW.user_id
                AND account_id = NEW.account_id FOR SHARE;
    END IF;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        SET last_source = GREATEST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        IF first_source IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = first_source AND user_id = NEW.user_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT id INTO locked_source FROM chart_of_accounts
                    WHERE id = last_source AND user_id = NEW.user_id FOR SHARE;
            END IF;
        END IF;
    END;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        SET last_source = GREATEST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        IF first_source IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = first_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                    WHERE chart_account_id = last_source AND user_id = NEW.user_id AND review_id = NEW.review_id FOR SHARE;
            END IF;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = NEW.review_id AND user_id = NEW.user_id
            AND chart_account_id = line_chart FOR SHARE;
    IF target_type IS NULL OR CAST(target_type AS BINARY) <> 'asset' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires a represented asset line';
    END IF;
    IF NOT ((line_debit > 0 AND line_credit = 0) OR (line_credit > 0 AND line_debit = 0))
        OR NEW.amount > GREATEST(line_debit, line_credit) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution amount exceeds its positive source line';
    END IF;
    IF CAST(NEW.target_kind AS BINARY) = 'account' THEN
        SELECT chart_account_id INTO account_chart FROM operational_reconciliation_accounts
            WHERE review_id = NEW.review_id AND user_id = NEW.user_id
                AND account_id = NEW.account_id FOR SHARE;
        IF account_chart IS NULL OR account_chart <> line_chart THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution account must use the source line reviewed chart';
        END IF;
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
            'orr_attributions_bd' => ['operational_reconciliation_attributions', 'DELETE', <<<'SQL'
BEGIN
    DECLARE parent_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE parent_completed DATETIME(6) DEFAULT NULL;
    DECLARE target_type VARCHAR(16) DEFAULT NULL;
    DECLARE membership_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE journal_status VARCHAR(16) DEFAULT NULL;
    DECLARE line_chart BIGINT UNSIGNED DEFAULT NULL;
    DECLARE line_debit DECIMAL(15,2) DEFAULT NULL;
    DECLARE line_credit DECIMAL(15,2) DEFAULT NULL;
    DECLARE account_chart BIGINT UNSIGNED DEFAULT NULL;
    SELECT status INTO journal_status FROM journal_entries
        WHERE id = OLD.journal_entry_id AND user_id = OLD.user_id FOR SHARE;
    IF journal_status IS NULL OR CAST(journal_status AS BINARY) <> 'posted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires a posted journal';
    END IF;
    SELECT chart_account_id, debit, credit INTO line_chart, line_debit, line_credit FROM journal_lines
        WHERE user_id = OLD.user_id AND journal_entry_id = OLD.journal_entry_id
            AND id = OLD.journal_line_id FOR SHARE;
    IF line_chart IS NULL OR line_debit IS NULL OR line_credit IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution line must belong to its owned journal';
    END IF;
    SELECT journal_entry_id INTO membership_id FROM operational_reconciliation_journals
        WHERE review_id = OLD.review_id AND user_id = OLD.user_id
            AND journal_entry_id = OLD.journal_entry_id FOR SHARE;
    IF membership_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires owned review journal membership';
    END IF;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF OLD.account_id IS NOT NULL THEN
            SELECT id INTO locked_source FROM accounts
                WHERE id = OLD.account_id AND user_id = OLD.user_id FOR SHARE;
        END IF;
    END;

    BEGIN
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        IF OLD.account_id IS NOT NULL THEN
            SELECT account_id INTO locked_source FROM operational_reconciliation_accounts
                WHERE account_id = OLD.account_id AND user_id = OLD.user_id AND review_id = OLD.review_id FOR SHARE;
        END IF;
    END;
    IF CAST(OLD.target_kind AS BINARY) = 'account' THEN
        SELECT chart_account_id INTO account_chart FROM operational_reconciliation_accounts
            WHERE review_id = OLD.review_id AND user_id = OLD.user_id
                AND account_id = OLD.account_id FOR SHARE;
    END IF;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        SET last_source = GREATEST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        IF first_source IS NOT NULL THEN
            SELECT id INTO locked_source FROM chart_of_accounts
                WHERE id = first_source AND user_id = OLD.user_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT id INTO locked_source FROM chart_of_accounts
                    WHERE id = last_source AND user_id = OLD.user_id FOR SHARE;
            END IF;
        END IF;
    END;

    BEGIN
        DECLARE first_source BIGINT UNSIGNED;
        DECLARE last_source BIGINT UNSIGNED;
        DECLARE locked_source BIGINT UNSIGNED DEFAULT NULL;
        SET first_source = LEAST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        SET last_source = GREATEST(COALESCE(line_chart, account_chart), COALESCE(account_chart, line_chart));
        IF first_source IS NOT NULL THEN
            SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                WHERE chart_account_id = first_source AND user_id = OLD.user_id AND review_id = OLD.review_id FOR SHARE;
            IF last_source <> first_source THEN
                SELECT chart_account_id INTO locked_source FROM operational_reconciliation_charts
                    WHERE chart_account_id = last_source AND user_id = OLD.user_id AND review_id = OLD.review_id FOR SHARE;
            END IF;
        END IF;
    END;
    SELECT type_snapshot INTO target_type FROM operational_reconciliation_charts
        WHERE review_id = OLD.review_id AND user_id = OLD.user_id
            AND chart_account_id = line_chart FOR SHARE;
    IF target_type IS NULL OR CAST(target_type AS BINARY) <> 'asset' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution requires a represented asset line';
    END IF;
    IF NOT ((line_debit > 0 AND line_credit = 0) OR (line_credit > 0 AND line_debit = 0))
        OR OLD.amount > GREATEST(line_debit, line_credit) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution amount exceeds its positive source line';
    END IF;
    IF CAST(OLD.target_kind AS BINARY) = 'account' THEN
        SELECT chart_account_id INTO account_chart FROM operational_reconciliation_accounts
            WHERE review_id = OLD.review_id AND user_id = OLD.user_id
                AND account_id = OLD.account_id FOR SHARE;
        IF account_chart IS NULL OR account_chart <> line_chart THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attribution account must use the source line reviewed chart';
        END IF;
    END IF;
    SELECT id, completed_at INTO parent_id, parent_completed FROM operational_reconciliation_reviews
        WHERE id = OLD.review_id AND user_id = OLD.user_id FOR SHARE;
    IF parent_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence requires an owned review parent';
    END IF;
    IF parent_completed IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sealed reconciliation evidence cannot be mutated';
    END IF;
END
SQL],
        ];
    }
};
