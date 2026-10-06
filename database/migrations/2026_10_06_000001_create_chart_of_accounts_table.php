<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chart_of_accounts')) {
            throw new RuntimeException('Accounting table chart_of_accounts already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        // All columns, indexes, and constraints are created in one atomic MySQL DDL.
        DB::statement(<<<'SQL'
CREATE TABLE `chart_of_accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `parent_id` BIGINT UNSIGNED NULL,
    `code` VARCHAR(32) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `type` VARCHAR(16) NOT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `coa_owner_code_unique` (`user_id`, `code`),
    UNIQUE KEY `coa_id_owner_unique` (`id`, `user_id`),
    KEY `coa_parent_owner_index` (`parent_id`, `user_id`),
    KEY `coa_owner_type_active_index` (`user_id`, `type`, `is_active`, `id`),
    CONSTRAINT `coa_type_check` CHECK (CAST(`type` AS BINARY) IN ('asset', 'liability', 'equity', 'revenue', 'expense')),
    CONSTRAINT `coa_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `coa_parent_owner_fk` FOREIGN KEY (`parent_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }
        if (DB::table('chart_of_accounts')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty accounting table chart_of_accounts.');
        }
        if (Schema::hasTable('journal_lines')) {
            throw new RuntimeException('Drop journal_lines through its guarded migration before chart_of_accounts.');
        }
        Schema::drop('chart_of_accounts');
    }
};
