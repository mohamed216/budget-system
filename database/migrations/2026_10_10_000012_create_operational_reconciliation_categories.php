<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_reconciliation_categories')) {
            throw new RuntimeException('Evidence table operational_reconciliation_categories already exists; inspect schema and migration history.');
        }
        $this->requireParent('operational_reconciliation_reviews', ['id', 'user_id']);
        $this->requireParent('categories', ['id', 'user_id']);
        $this->requireParent('operational_reconciliation_charts', ['review_id', 'user_id', 'chart_account_id']);

        // Foundation only: no seeding, source eligibility guards, or sealing triggers.
        DB::statement(<<<'SQL'
CREATE TABLE `operational_reconciliation_categories` (
    `review_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `category_type_snapshot` VARCHAR(16) NOT NULL,
    `chart_account_id` BIGINT UNSIGNED NOT NULL,
    `cash_flow_activity` VARCHAR(16) NOT NULL,
    PRIMARY KEY (`review_id`, `category_id`),
    KEY `orcat_review_owner_index` (`review_id`, `user_id`),
    KEY `orcat_category_owner_index` (`category_id`, `user_id`),
    KEY `orcat_review_owner_chart_index` (`review_id`, `user_id`, `chart_account_id`),
    CONSTRAINT `orcat_type_check` CHECK (CAST(`category_type_snapshot` AS BINARY) IN ('income', 'expense')),
    CONSTRAINT `orcat_activity_check` CHECK (CAST(`cash_flow_activity` AS BINARY) IN ('operating', 'investing', 'financing')),
    CONSTRAINT `orcat_review_owner_fk` FOREIGN KEY (`review_id`, `user_id`) REFERENCES `operational_reconciliation_reviews` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orcat_category_owner_fk` FOREIGN KEY (`category_id`, `user_id`) REFERENCES `categories` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orcat_review_chart_fk` FOREIGN KEY (`review_id`, `user_id`, `chart_account_id`) REFERENCES `operational_reconciliation_charts` (`review_id`, `user_id`, `chart_account_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_reconciliation_categories')) {
            return;
        }
        if (DB::table('operational_reconciliation_categories')->exists()) {
            throw new RuntimeException('Refusing to drop populated evidence table operational_reconciliation_categories.');
        }
        foreach ([] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop dependent evidence table {$dependent} through its guarded migration first.");
            }
        }
        $referenced = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'operational_reconciliation_categories')
            ->where(function ($query) {
                $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())
                    ->orWhere('TABLE_NAME', '<>', 'operational_reconciliation_categories');
            })->exists();
        if ($referenced) {
            throw new RuntimeException('External constraints still reference operational_reconciliation_categories; refusing rollback.');
        }
        Schema::drop('operational_reconciliation_categories');
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
