<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_account_mappings')) {
            throw new RuntimeException('Operational account mappings already exist; inspect schema and migration history.');
        }
        $this->requireKey('accounts', ['id', 'user_id']);
        $this->requireKey('chart_of_accounts', ['id', 'user_id']);

        DB::statement(<<<'SQL'
CREATE TABLE `operational_account_mappings` (
    `account_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `chart_account_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`account_id`),
    UNIQUE KEY `oam_account_owner_unique` (`account_id`, `user_id`),
    KEY `oam_chart_owner_index` (`chart_account_id`, `user_id`),
    CONSTRAINT `oam_account_owner_fk` FOREIGN KEY (`account_id`, `user_id`) REFERENCES `accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `oam_chart_owner_fk` FOREIGN KEY (`chart_account_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_account_mappings')) {
            return;
        }
        if (Schema::hasTable('operational_transaction_journals')
            || DB::table('operational_account_mappings')->exists()) {
            throw new RuntimeException('Refusing to drop referenced or non-empty operational account mappings.');
        }
        Schema::drop('operational_account_mappings');
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
