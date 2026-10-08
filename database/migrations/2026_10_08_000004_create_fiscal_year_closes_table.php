<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fiscal_year_closes')) {
            throw new RuntimeException('Accounting table fiscal_year_closes already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `fiscal_year_closes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `currency` CHAR(3) NOT NULL,
    `retained_earnings_account_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NULL,
    `closed_at` DATETIME(6) NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `fyc_journal_unique` (`journal_entry_id`),
    KEY `fyc_owner_start_end_index` (`user_id`, `start_date`, `end_date`, `id`),
    KEY `fyc_owner_end_start_index` (`user_id`, `end_date`, `start_date`, `id`),
    KEY `fyc_account_owner_index` (`retained_earnings_account_id`, `user_id`),
    KEY `fyc_journal_owner_index` (`journal_entry_id`, `user_id`),
    CONSTRAINT `fyc_dates_check` CHECK (`start_date` <= `end_date`),
    CONSTRAINT `fyc_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fyc_account_owner_fk` FOREIGN KEY (`retained_earnings_account_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fyc_journal_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_year_closes')) {
            return;
        }
        if (DB::table('fiscal_year_closes')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty accounting table fiscal_year_closes.');
        }
        Schema::drop('fiscal_year_closes');
    }
};
