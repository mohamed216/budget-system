<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_entries')) {
            throw new RuntimeException('Accounting table journal_entries already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        // All columns, indexes, and constraints are created in one atomic MySQL DDL.
        DB::statement(<<<'SQL'
CREATE TABLE `journal_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `entry_date` DATE NOT NULL,
    `currency` CHAR(3) NOT NULL,
    `reference` VARCHAR(100) NULL,
    `description` TEXT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `posted_at` DATETIME(6) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `je_id_owner_unique` (`id`, `user_id`),
    KEY `je_owner_status_date_index` (`user_id`, `status`, `entry_date`, `id`),
    CONSTRAINT `je_status_check` CHECK (CAST(`status` AS BINARY) IN ('draft', 'posted')),
    CONSTRAINT `je_posted_at_check` CHECK ((CAST(`status` AS BINARY) = 'draft' AND `posted_at` IS NULL) OR (CAST(`status` AS BINARY) = 'posted' AND `posted_at` IS NOT NULL)),
    CONSTRAINT `je_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }
        if (DB::table('journal_entries')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty accounting table journal_entries.');
        }
        if (Schema::hasTable('journal_lines')) {
            throw new RuntimeException('Drop journal_lines through its guarded migration before journal_entries.');
        }
        Schema::drop('journal_entries');
    }
};
