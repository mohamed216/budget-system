<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_category_mappings')) {
            throw new RuntimeException('Operational category mappings already exist; inspect schema and migration history.');
        }
        $this->requireKey('categories', ['id', 'user_id']);
        $this->requireKey('chart_of_accounts', ['id', 'user_id']);
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Missing users table for category review ownership.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `operational_category_mappings` (
    `category_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `chart_account_id` BIGINT UNSIGNED NOT NULL,
    `cash_flow_activity` VARCHAR(16) NULL,
    `reviewed_by` BIGINT UNSIGNED NULL,
    `reviewed_at` DATETIME(6) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`category_id`),
    UNIQUE KEY `ocm_category_owner_unique` (`category_id`, `user_id`),
    KEY `ocm_chart_owner_index` (`chart_account_id`, `user_id`),
    KEY `ocm_reviewer_index` (`reviewed_by`),
    CONSTRAINT `ocm_activity_check` CHECK (`cash_flow_activity` IS NULL OR CAST(`cash_flow_activity` AS BINARY) IN ('operating', 'investing', 'financing')),
    CONSTRAINT `ocm_review_check` CHECK ((`cash_flow_activity` IS NULL AND `reviewed_by` IS NULL AND `reviewed_at` IS NULL) OR (`cash_flow_activity` IS NOT NULL AND `reviewed_by` IS NOT NULL AND `reviewed_at` IS NOT NULL)),
    CONSTRAINT `ocm_category_owner_fk` FOREIGN KEY (`category_id`, `user_id`) REFERENCES `categories` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `ocm_chart_owner_fk` FOREIGN KEY (`chart_account_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `ocm_reviewer_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_category_mappings')) {
            return;
        }
        if (Schema::hasTable('operational_transaction_journals')
            || DB::table('operational_category_mappings')->exists()) {
            throw new RuntimeException('Refusing to drop referenced or non-empty operational category mappings.');
        }
        Schema::drop('operational_category_mappings');
    }

    private function requireKey(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! collect(Schema::getIndexes($table))->contains(
            fn (array $index) => $index['unique'] && $index['columns'] === $columns
        )) {
            throw new RuntimeException("Missing referenceable owner key on {$table}.");
        }
    }
};
