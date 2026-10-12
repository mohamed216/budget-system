<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_reconciliation_reviews')) {
            throw new RuntimeException('Evidence table operational_reconciliation_reviews already exists; inspect schema and migration history.');
        }
        $this->requireParent('users', ['id']);

        // Without this mode, MySQL can implicitly initialize the first TIMESTAMP.
        // A missing scope-review timestamp must never become inferred evidence.
        if (! DB::selectOne('SELECT @@explicit_defaults_for_timestamp AS enabled')->enabled) {
            throw new RuntimeException('Explicit timestamp defaults are required for reconciliation evidence.');
        }

        if (! Schema::hasTable('operational_accounting_integrations')
            || ! Schema::hasColumns('operational_accounting_integrations', ['reconciliation_reference', 'reconciled_at'])) {
            throw new RuntimeException('Missing Phase 3 integration foundation.');
        }
        if (DB::table('operational_accounting_integrations')->whereNotNull('reconciliation_reference')
            ->orWhereNotNull('reconciled_at')->exists()) {
            throw new RuntimeException('Existing reconciliation evidence requires explicit review; refusing to invent internal reviews.');
        }

        // Foundation only: no seeding, source eligibility guards, or sealing triggers.
        DB::statement(<<<'SQL'
CREATE TABLE `operational_reconciliation_reviews` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `reference` VARCHAR(255) NOT NULL,
    `historical_transaction_strategy` VARCHAR(32) NOT NULL,
    `accounting_currency` CHAR(3) NOT NULL,
    `transaction_high_water_id` BIGINT UNSIGNED NOT NULL,
    `scope_reviewed_at` TIMESTAMP(0) NOT NULL,
    `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `completed_at` DATETIME(6) NULL DEFAULT NULL,
    `supersedes_review_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `balance_only_disclosure_code` VARCHAR(32) NULL DEFAULT NULL,
    `balance_only_disclosure_text` TEXT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `orr_id_owner_unique` (`id`, `user_id`),
    UNIQUE KEY `orr_owner_reference_unique` (`user_id`, `reference`),
    KEY `orr_reviewer_index` (`reviewed_by`),
    KEY `orr_supersedes_owner_index` (`supersedes_review_id`, `user_id`),
    CONSTRAINT `orr_strategy_check` CHECK (CAST(`historical_transaction_strategy` AS BINARY) IN ('historical_backfill', 'balance_only')),
    CONSTRAINT `orr_currency_check` CHECK (REGEXP_LIKE(`accounting_currency`, '^[A-Z]{3}$', 'c')),
    CONSTRAINT `orr_reference_check` CHECK (CHAR_LENGTH(`reference`) = 36 AND REGEXP_LIKE(`reference`, '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', 'c')),
    CONSTRAINT `orr_completion_pair_check` CHECK ((`reviewed_by` IS NULL AND `completed_at` IS NULL) OR (`reviewed_by` IS NOT NULL AND `completed_at` IS NOT NULL)),
    CONSTRAINT `orr_completion_time_check` CHECK (`completed_at` IS NULL OR `completed_at` >= `created_at`),
    CONSTRAINT `orr_disclosure_check` CHECK (
        (CAST(`historical_transaction_strategy` AS BINARY) = 'historical_backfill' AND `balance_only_disclosure_code` IS NULL AND `balance_only_disclosure_text` IS NULL)
        OR (CAST(`historical_transaction_strategy` AS BINARY) = 'balance_only' AND `balance_only_disclosure_code` IS NOT NULL AND CAST(`balance_only_disclosure_code` AS BINARY) = 'balance_only_legacy_history_v1' AND `balance_only_disclosure_text` IS NOT NULL AND REGEXP_LIKE(`balance_only_disclosure_text`, '[^[:space:]]', 'c'))
    ),
    CONSTRAINT `orr_owner_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orr_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orr_supersedes_owner_fk` FOREIGN KEY (`supersedes_review_id`, `user_id`) REFERENCES `operational_reconciliation_reviews` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_reconciliation_reviews')) {
            return;
        }
        if (DB::table('operational_reconciliation_reviews')->exists()) {
            throw new RuntimeException('Refusing to drop populated evidence table operational_reconciliation_reviews.');
        }
        foreach (['operational_reconciliation_charts', 'operational_reconciliation_accounts', 'operational_reconciliation_categories', 'operational_reconciliation_journals', 'operational_reconciliation_attributions'] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop dependent evidence table {$dependent} through its guarded migration first.");
            }
        }
        $referenced = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'operational_reconciliation_reviews')
            ->where(function ($query) {
                $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())
                    ->orWhere('TABLE_NAME', '<>', 'operational_reconciliation_reviews');
            })->exists();
        if ($referenced) {
            throw new RuntimeException('External constraints still reference operational_reconciliation_reviews; refusing rollback.');
        }
        Schema::drop('operational_reconciliation_reviews');
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
