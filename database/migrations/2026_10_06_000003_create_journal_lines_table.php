<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_lines')) {
            throw new RuntimeException('Accounting table journal_lines already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        // All columns, indexes, and constraints are created in one atomic MySQL DDL.
        DB::statement(<<<'SQL'
CREATE TABLE `journal_lines` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    `chart_account_id` BIGINT UNSIGNED NOT NULL,
    `line_number` SMALLINT UNSIGNED NOT NULL,
    `debit` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `credit` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `description` VARCHAR(1000) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `jl_entry_number_unique` (`journal_entry_id`, `line_number`),
    KEY `jl_entry_owner_index` (`journal_entry_id`, `user_id`),
    KEY `jl_account_owner_index` (`chart_account_id`, `user_id`),
    CONSTRAINT `jl_number_check` CHECK (`line_number` > 0),
    CONSTRAINT `jl_side_check` CHECK ((`debit` > 0 AND `credit` = 0) OR (`credit` > 0 AND `debit` = 0)),
    CONSTRAINT `jl_entry_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `jl_account_owner_fk` FOREIGN KEY (`chart_account_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_lines')) {
            return;
        }
        if (DB::table('journal_lines')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty accounting table journal_lines.');
        }
        Schema::drop('journal_lines');
    }
};
