<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_reconciliation_charts')) {
            throw new RuntimeException('Evidence table operational_reconciliation_charts already exists; inspect schema and migration history.');
        }
        $this->requireParent('operational_reconciliation_reviews', ['id', 'user_id']);
        $this->requireParent('chart_of_accounts', ['id', 'user_id']);

        // Foundation only: no seeding, source eligibility guards, or sealing triggers.
        DB::statement(<<<'SQL'
CREATE TABLE `operational_reconciliation_charts` (
    `review_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `chart_account_id` BIGINT UNSIGNED NOT NULL,
    `type_snapshot` VARCHAR(16) NOT NULL,
    `cash_role_snapshot` VARCHAR(16) NOT NULL,
    `is_active_snapshot` BOOLEAN NOT NULL,
    PRIMARY KEY (`review_id`, `chart_account_id`),
    UNIQUE KEY `orc_review_owner_chart_unique` (`review_id`, `user_id`, `chart_account_id`),
    KEY `orc_chart_owner_index` (`chart_account_id`, `user_id`),
    CONSTRAINT `orc_type_check` CHECK (CAST(`type_snapshot` AS BINARY) IN ('asset', 'liability', 'equity', 'revenue', 'expense')),
    CONSTRAINT `orc_role_check` CHECK (CAST(`cash_role_snapshot` AS BINARY) IN ('non_cash', 'cash', 'cash_equivalent')),
    CONSTRAINT `orc_cash_asset_check` CHECK (CAST(`cash_role_snapshot` AS BINARY) = 'non_cash' OR CAST(`type_snapshot` AS BINARY) = 'asset'),
    CONSTRAINT `orc_active_check` CHECK (`is_active_snapshot` IN (0, 1)),
    CONSTRAINT `orc_review_owner_fk` FOREIGN KEY (`review_id`, `user_id`) REFERENCES `operational_reconciliation_reviews` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orc_chart_owner_fk` FOREIGN KEY (`chart_account_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_reconciliation_charts')) {
            return;
        }
        if (DB::table('operational_reconciliation_charts')->exists()) {
            throw new RuntimeException('Refusing to drop populated evidence table operational_reconciliation_charts.');
        }
        foreach (['operational_reconciliation_accounts', 'operational_reconciliation_categories'] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop dependent evidence table {$dependent} through its guarded migration first.");
            }
        }
        $referenced = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'operational_reconciliation_charts')
            ->where(function ($query) {
                $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())
                    ->orWhere('TABLE_NAME', '<>', 'operational_reconciliation_charts');
            })->exists();
        if ($referenced) {
            throw new RuntimeException('External constraints still reference operational_reconciliation_charts; refusing rollback.');
        }
        Schema::drop('operational_reconciliation_charts');
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
