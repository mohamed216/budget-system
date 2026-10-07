<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opening_balance_batches')) {
            throw new RuntimeException('Accounting table opening_balance_batches already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `opening_balance_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `opening_date` DATE NOT NULL,
    `currency` CHAR(3) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `journal_entry_id` BIGINT UNSIGNED NULL,
    `posted_at` DATETIME(6) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `obb_id_owner_unique` (`id`, `user_id`),
    UNIQUE KEY `obb_journal_unique` (`journal_entry_id`),
    KEY `obb_owner_status_date_index` (`user_id`, `status`, `opening_date`, `id`),
    KEY `obb_journal_owner_index` (`journal_entry_id`, `user_id`),
    CONSTRAINT `obb_status_check` CHECK (CAST(`status` AS BINARY) IN ('draft', 'posted')),
    CONSTRAINT `obb_posted_link_check` CHECK (
        (CAST(`status` AS BINARY) = 'draft' AND `posted_at` IS NULL AND `journal_entry_id` IS NULL)
        OR (CAST(`status` AS BINARY) = 'posted' AND `posted_at` IS NOT NULL AND `journal_entry_id` IS NOT NULL)
    ),
    CONSTRAINT `obb_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `obb_journal_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('opening_balance_batches')) {
            return;
        }
        if (DB::table('opening_balance_batches')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty accounting table opening_balance_batches.');
        }
        if (Schema::hasTable('opening_balance_lines')) {
            throw new RuntimeException('Drop opening_balance_lines through its guarded migration before opening_balance_batches.');
        }
        Schema::drop('opening_balance_batches');
    }
};
