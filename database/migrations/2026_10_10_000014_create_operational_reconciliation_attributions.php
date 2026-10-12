<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_reconciliation_attributions')) {
            throw new RuntimeException('Evidence table operational_reconciliation_attributions already exists; inspect schema and migration history.');
        }
        $this->requireParent('operational_reconciliation_reviews', ['id', 'user_id']);
        $this->requireParent('operational_reconciliation_journals', ['review_id', 'user_id', 'journal_entry_id']);
        $this->requireParent('journal_lines', ['user_id', 'journal_entry_id', 'id']);
        $this->requireParent('operational_reconciliation_accounts', ['review_id', 'user_id', 'account_id']);

        // Foundation only: no seeding, source eligibility guards, or sealing triggers.
        DB::statement(<<<'SQL'
CREATE TABLE `operational_reconciliation_attributions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `review_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    `journal_line_id` BIGINT UNSIGNED NOT NULL,
    `target_kind` VARCHAR(16) NOT NULL,
    `account_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `purpose` VARCHAR(32) NULL DEFAULT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `rationale` TEXT NULL,
    `target_key` VARCHAR(37) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (
        CASE WHEN `account_id` IS NULL THEN 'excluded'
        ELSE CONCAT(CAST(`account_id` AS CHAR), ':', `purpose`) END
    ) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `orat_line_target_unique` (`review_id`, `journal_line_id`, `target_key`),
    KEY `orat_review_owner_journal_index` (`review_id`, `user_id`, `journal_entry_id`),
    KEY `orat_owner_journal_line_index` (`user_id`, `journal_entry_id`, `journal_line_id`),
    KEY `orat_review_owner_account_index` (`review_id`, `user_id`, `account_id`),
    CONSTRAINT `orat_amount_check` CHECK (`amount` > 0),
    CONSTRAINT `orat_target_check` CHECK (
        (CAST(`target_kind` AS BINARY) = 'account' AND `account_id` IS NOT NULL AND `account_id` > 0
            AND `purpose` IS NOT NULL AND CAST(`purpose` AS BINARY) IN ('opening_balance', 'existing_history')
            AND (`rationale` IS NULL OR REGEXP_LIKE(`rationale`, '[^[:space:]]', 'c')))
        OR (CAST(`target_kind` AS BINARY) = 'excluded' AND `account_id` IS NULL AND `purpose` IS NULL
            AND `rationale` IS NOT NULL AND REGEXP_LIKE(`rationale`, '[^[:space:]]', 'c'))
    ),
    CONSTRAINT `orat_review_owner_fk` FOREIGN KEY (`review_id`, `user_id`) REFERENCES `operational_reconciliation_reviews` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orat_review_journal_fk` FOREIGN KEY (`review_id`, `user_id`, `journal_entry_id`) REFERENCES `operational_reconciliation_journals` (`review_id`, `user_id`, `journal_entry_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orat_journal_line_fk` FOREIGN KEY (`user_id`, `journal_entry_id`, `journal_line_id`) REFERENCES `journal_lines` (`user_id`, `journal_entry_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orat_review_account_fk` FOREIGN KEY (`review_id`, `user_id`, `account_id`) REFERENCES `operational_reconciliation_accounts` (`review_id`, `user_id`, `account_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_reconciliation_attributions')) {
            return;
        }
        if (DB::table('operational_reconciliation_attributions')->exists()) {
            throw new RuntimeException('Refusing to drop populated evidence table operational_reconciliation_attributions.');
        }
        foreach ([] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop dependent evidence table {$dependent} through its guarded migration first.");
            }
        }
        $referenced = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'operational_reconciliation_attributions')
            ->where(function ($query) {
                $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())
                    ->orWhere('TABLE_NAME', '<>', 'operational_reconciliation_attributions');
            })->exists();
        if ($referenced) {
            throw new RuntimeException('External constraints still reference operational_reconciliation_attributions; refusing rollback.');
        }
        Schema::drop('operational_reconciliation_attributions');
    }

    private function requireParent(string $table, array $key): void
    {
        $engine = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('ENGINE');
        if ($engine !== 'InnoDB' || ! collect(Schema::getIndexes($table))->contains(
            fn (array $index) => $index['unique'] && $index['columns'] === $key
        )) {
            throw new RuntimeException("Missing InnoDB referenceable parent key on {$table}.");
        }
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
            ->whereIn('COLUMN_NAME', $key)->get()->keyBy('COLUMN_NAME');
        foreach ($key as $column) {
            $definition = $columns->get($column);
            if ($definition === null || strtolower($definition->COLUMN_TYPE) !== 'bigint unsigned'
                || ($column !== 'user_id' && $definition->IS_NULLABLE !== 'NO')) {
                throw new RuntimeException("Incompatible parent column {$table}.{$column}; expected BIGINT UNSIGNED.");
            }
        }
        // Only legacy operational parent owners may remain nullable.
        if (in_array('user_id', $key, true) && ! in_array($table, ['accounts', 'categories', 'transactions'], true)
            && $columns->get('user_id')?->IS_NULLABLE !== 'NO') {
            throw new RuntimeException("Nullable accounting/evidence parent owner on {$table}.");
        }
    }
};
