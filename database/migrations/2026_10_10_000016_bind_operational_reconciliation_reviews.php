<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'operational_accounting_integrations';

    private const PAIR = '(reconciliation_reference IS NULL AND reconciled_at IS NULL) OR (reconciliation_reference IS NOT NULL AND reconciled_at IS NOT NULL)';

    public function up(): void
    {
        $this->requireFoundation();
        if ($this->inspectObjects() !== []) {
            throw new RuntimeException('Reconciliation binding objects already exist; inspect migration history.');
        }
        // Validate ALL existing rows before MySQL's first implicitly committing DDL.
        // No adoption of old external/free-form completion evidence or repairs.
        $invalid = DB::selectOne(<<<'SQL'
SELECT i.user_id FROM operational_accounting_integrations i
LEFT JOIN operational_reconciliation_reviews r
    ON r.user_id = i.user_id AND r.reference = i.reconciliation_reference
WHERE (i.reconciliation_reference IS NULL AND i.reconciled_at IS NOT NULL)
    OR (i.reconciliation_reference IS NOT NULL AND i.reconciled_at IS NULL)
    OR (CAST(i.state AS BINARY) = 'legacy' AND i.reconciliation_reference IS NOT NULL)
    OR (CAST(i.state AS BINARY) IN ('ready', 'active') AND i.reconciliation_reference IS NULL)
    OR (i.reconciliation_reference IS NOT NULL AND (
        r.id IS NULL OR r.reviewed_by IS NULL OR r.completed_at IS NULL
        OR NOT (CAST(i.reconciliation_reference AS BINARY) <=> CAST(r.reference AS BINARY))
        OR NOT (CAST(i.historical_transaction_strategy AS BINARY) <=> CAST(r.historical_transaction_strategy AS BINARY))
        OR NOT (CAST(i.accounting_currency AS BINARY) <=> CAST(r.accounting_currency AS BINARY))
        OR NOT (i.transaction_high_water_id <=> r.transaction_high_water_id)
        OR NOT (i.scope_reviewed_at <=> r.scope_reviewed_at)
        OR NOT (i.reconciled_at <=> r.completed_at)))
LIMIT 1
SQL);
        if ($invalid !== null) {
            throw new RuntimeException('Invalid existing reconciliation binding; refusing installation without repair.');
        }
        DB::statement('ALTER TABLE '.self::TABLE.'
            ADD INDEX oai_review_ref_idx (user_id, reconciliation_reference),
            ADD CONSTRAINT oai_review_pair_check CHECK ('.self::PAIR.') ENFORCED,
            ADD CONSTRAINT oai_review_owner_fk FOREIGN KEY (user_id, reconciliation_reference)
                REFERENCES operational_reconciliation_reviews (user_id, reference)
                ON DELETE RESTRICT ON UPDATE RESTRICT');
        foreach ($this->definitions() as $name => [$event, $body]) {
            DB::unprepared('CREATE TRIGGER `'.$name.'` BEFORE '.$event.' ON '.self::TABLE.' FOR EACH ROW '.$body);
        }
    }

    public function down(): void
    {
        // Inspect the complete set before the first DROP, including interrupted
        // installation. Unknown/altered objects are never removed or adopted.
        $objects = $this->inspectObjects();
        foreach (array_keys($this->definitions()) as $name) {
            if (isset($objects[$name])) {
                DB::unprepared("DROP TRIGGER `{$name}`");
            }
        }
        foreach (['oai_review_owner_fk' => 'FOREIGN KEY', 'oai_review_pair_check' => 'CHECK', 'oai_review_ref_idx' => 'INDEX'] as $name => $kind) {
            if (isset($objects[$name])) {
                DB::statement('ALTER TABLE '.self::TABLE." DROP {$kind} `{$name}`");
            }
        }
        // Reviews, integrations, and all 000015 protections remain untouched.
    }

    private function requireFoundation(): void
    {
        $expected = [
            self::TABLE => [
                'user_id' => ['bigint unsigned', false], 'state' => ['varchar(16)', false],
                'accounting_currency' => ['char(3)', true], 'historical_transaction_strategy' => ['varchar(32)', true],
                'transaction_high_water_id' => ['bigint unsigned', true], 'scope_reviewed_at' => ['timestamp', true],
                'reconciliation_reference' => ['varchar(255)', true], 'reconciled_at' => ['datetime(6)', true],
            ],
            'operational_reconciliation_reviews' => [
                'id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false],
                'reference' => ['varchar(255)', false], 'accounting_currency' => ['char(3)', false],
                'historical_transaction_strategy' => ['varchar(32)', false], 'transaction_high_water_id' => ['bigint unsigned', false],
                'scope_reviewed_at' => ['timestamp', false], 'reviewed_by' => ['bigint unsigned', true],
                'completed_at' => ['datetime(6)', true],
            ],
        ];
        foreach ($expected as $table => $fields) {
            $metadata = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->first();
            if ($metadata === null || $metadata->ENGINE !== 'InnoDB') {
                throw new RuntimeException("Missing InnoDB reconciliation binding foundation {$table}.");
            }
            $columns = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($fields as $name => [$type, $nullable]) {
                if (! isset($columns[$name]) || strtolower($columns[$name]['type']) !== $type || $columns[$name]['nullable'] !== $nullable) {
                    throw new RuntimeException("Incompatible reconciliation binding column {$table}.{$name}.");
                }
            }
        }
        foreach ([self::TABLE => 'reconciliation_reference', 'operational_reconciliation_reviews' => 'reference'] as $table => $column) {
            $metadata = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->first();
            if ($metadata->CHARACTER_SET_NAME !== 'utf8mb4' || $metadata->COLLATION_NAME !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException('Incompatible reconciliation reference charset/collation.');
            }
        }
        if (! collect(Schema::getIndexes('operational_reconciliation_reviews'))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === ['user_id', 'reference']
        )) {
            throw new RuntimeException('Missing same-owner reconciliation reference unique key.');
        }
        // An internal review is usable only with its already-installed immutable
        // header guards. Compare 000015's reviewed definitions without executing
        // its up/down methods or adopting altered protection objects.
        $protection = require database_path('migrations/2026_10_10_000015_protect_operational_reconciliation_evidence.php');
        $definitions = (new ReflectionMethod($protection, 'definitions'))->invoke($protection);
        foreach (['orr_header_bi' => 'INSERT', 'orr_header_bu' => 'UPDATE', 'orr_header_bd' => 'DELETE'] as $name => $event) {
            $trigger = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', $name)->first();
            if ($trigger === null || $trigger->EVENT_OBJECT_TABLE !== 'operational_reconciliation_reviews'
                || $trigger->ACTION_TIMING !== 'BEFORE' || $trigger->EVENT_MANIPULATION !== $event
                || trim($trigger->ACTION_STATEMENT) !== trim($definitions[$name][2])) {
                throw new RuntimeException('Missing reconciliation evidence header protections.');
            }
        }
    }

    private function inspectObjects(): array
    {
        $objects = [];
        $definitions = $this->definitions();
        $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->where(fn ($query) => $query->where('EVENT_OBJECT_TABLE', self::TABLE)->orWhereIn('TRIGGER_NAME', array_keys($definitions)))->get();
        foreach ($triggers as $trigger) {
            $expected = $definitions[$trigger->TRIGGER_NAME] ?? null;
            if ($expected === null || $trigger->EVENT_OBJECT_TABLE !== self::TABLE || $trigger->ACTION_TIMING !== 'BEFORE'
                || $trigger->EVENT_MANIPULATION !== $expected[0] || trim($trigger->ACTION_STATEMENT) !== trim($expected[1])) {
                throw new RuntimeException('Unexpected reconciliation binding trigger; refusing installation or rollback.');
            }
            $objects[$trigger->TRIGGER_NAME] = true;
        }
        if (! Schema::hasTable(self::TABLE)) {
            return $objects;
        }
        $foreign = collect(Schema::getForeignKeys(self::TABLE))->firstWhere('name', 'oai_review_owner_fk');
        if ($foreign !== null) {
            if ($foreign['columns'] !== ['user_id', 'reconciliation_reference']
                || $foreign['foreign_schema'] !== DB::getDatabaseName() || $foreign['foreign_table'] !== 'operational_reconciliation_reviews'
                || $foreign['foreign_columns'] !== ['user_id', 'reference']
                || strtolower($foreign['on_delete']) !== 'restrict' || strtolower($foreign['on_update']) !== 'restrict') {
                throw new RuntimeException('Unexpected reconciliation binding foreign key.');
            }
            $objects['oai_review_owner_fk'] = true;
        }
        $constraint = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)->where('CONSTRAINT_NAME', 'oai_review_pair_check')->first();
        if ($constraint !== null) {
            $clause = DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', 'oai_review_pair_check')->value('CHECK_CLAUSE');
            if ($constraint->CONSTRAINT_TYPE !== 'CHECK' || $constraint->ENFORCED !== 'YES'
                || ! is_string($clause) || ! $this->matchesPairCheck($clause)) {
                throw new RuntimeException('Unexpected reconciliation binding pair CHECK.');
            }
            $objects['oai_review_pair_check'] = true;
        }
        $index = collect(Schema::getIndexes(self::TABLE))->firstWhere('name', 'oai_review_ref_idx');
        if ($index !== null) {
            if ($index['columns'] !== ['user_id', 'reconciliation_reference'] || $index['unique']) {
                throw new RuntimeException('Unexpected reconciliation binding supporting index.');
            }
            $objects['oai_review_ref_idx'] = true;
        }

        return $objects;
    }

    private function matchesPairCheck(string $expression): bool
    {
        // Grouping is optional only around complete nodes. Never remove all
        // parentheses or reorder AND/OR; NULL combinations are the invariant.
        return preg_match(<<<'REGEX'
~\A\s*(?&pair)\s*\z
(?(DEFINE)
    (?<ref> (?: reconciliation_reference\b | `reconciliation_reference` | \(\s*(?&ref)\s*\) ) )
    (?<time> (?: reconciled_at\b | `reconciled_at` | \(\s*(?&time)\s*\) ) )
    (?<nr> (?: (?&ref)\s+IS\s+NULL\b | \(\s*(?&nr)\s*\) ) )
    (?<nt> (?: (?&time)\s+IS\s+NULL\b | \(\s*(?&nt)\s*\) ) )
    (?<pr> (?: (?&ref)\s+IS\s+NOT\s+NULL\b | \(\s*(?&pr)\s*\) ) )
    (?<pt> (?: (?&time)\s+IS\s+NOT\s+NULL\b | \(\s*(?&pt)\s*\) ) )
    (?<empty> (?: (?&nr)\s+AND\s+(?&nt) | \(\s*(?&empty)\s*\) ) )
    (?<bound> (?: (?&pr)\s+AND\s+(?&pt) | \(\s*(?&bound)\s*\) ) )
    (?<pair> (?: (?&empty)\s+OR\s+(?&bound) | \(\s*(?&pair)\s*\) ) )
)
~ix
REGEX, $expression) === 1;
    }

    private function definitions(): array
    {
        // Application protocol: owner/earlier sources prelocked, completed review
        // sealed under review X, then bind integration. Trigger validation locks
        // ONLY that review with a waiting CURRENT read. No owner/source discovery.
        // Implicit owner/review FK checks may wait; no trigger NOWAIT guarantee.
        $body = <<<'SQL'
BEGIN
    DECLARE review_reference VARCHAR(255) DEFAULT NULL;
    DECLARE review_strategy VARCHAR(32) DEFAULT NULL;
    DECLARE review_currency CHAR(3) DEFAULT NULL;
    DECLARE review_boundary BIGINT UNSIGNED DEFAULT NULL;
    DECLARE review_scope TIMESTAMP DEFAULT NULL;
    DECLARE review_reviewer BIGINT UNSIGNED DEFAULT NULL;
    DECLARE review_completed DATETIME(6) DEFAULT NULL;
    IF (NEW.reconciliation_reference IS NULL AND NEW.reconciled_at IS NOT NULL)
        OR (NEW.reconciliation_reference IS NOT NULL AND NEW.reconciled_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation reference and time must be paired';
    END IF;
    IF CAST(NEW.state AS BINARY) = 'legacy' AND NEW.reconciliation_reference IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Legacy integration cannot bind a reconciliation review';
    END IF;
    IF CAST(NEW.state AS BINARY) IN ('ready', 'active') AND NEW.reconciliation_reference IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ready or active integration requires a completed review binding';
    END IF;
    IF NEW.reconciliation_reference IS NOT NULL THEN
        IF CAST(NEW.state AS BINARY) NOT IN ('reconciling', 'ready', 'active') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid state for completed review binding';
        END IF;
        SELECT reference, historical_transaction_strategy, accounting_currency,
            transaction_high_water_id, scope_reviewed_at, reviewed_by, completed_at
            INTO review_reference, review_strategy, review_currency,
                review_boundary, review_scope, review_reviewer, review_completed
            FROM operational_reconciliation_reviews
            WHERE user_id = NEW.user_id AND reference = NEW.reconciliation_reference FOR SHARE;
        IF review_reference IS NULL OR review_reviewer IS NULL OR review_completed IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Binding requires an owned completed reconciliation review';
        END IF;
        IF NOT (CAST(NEW.reconciliation_reference AS BINARY) <=> CAST(review_reference AS BINARY)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation reference spelling must match exactly';
        END IF;
        IF NOT (CAST(NEW.historical_transaction_strategy AS BINARY) <=> CAST(review_strategy AS BINARY))
            OR NOT (CAST(NEW.accounting_currency AS BINARY) <=> CAST(review_currency AS BINARY))
            OR NOT (NEW.transaction_high_water_id <=> review_boundary)
            OR NOT (NEW.scope_reviewed_at <=> review_scope)
            OR NOT (NEW.reconciled_at <=> review_completed) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Reconciliation binding metadata must match the completed review';
        END IF;
    END IF;
END
SQL;

        $update = str_replace('    IF (NEW.reconciliation_reference', <<<'SQL'
    IF CAST(OLD.state AS BINARY) = 'active' AND NOT (CAST(NEW.state AS BINARY) <=> 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active integration cannot fall back to a pre-activation state';
    END IF;
    IF (NEW.reconciliation_reference
SQL, $body);

        return ['oai_review_bi' => ['INSERT', $body], 'oai_review_bu' => ['UPDATE', $update]];
    }
};
