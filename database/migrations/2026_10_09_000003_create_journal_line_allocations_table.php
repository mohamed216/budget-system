<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_line_allocations')) {
            throw new RuntimeException('Cash-flow allocation table already exists; inspect schema and migration history.');
        }
        DB::statement(<<<'SQL'
CREATE TABLE `journal_line_allocations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    `debit_line_id` BIGINT UNSIGNED NOT NULL,
    `credit_line_id` BIGINT UNSIGNED NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `category` VARCHAR(16) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `jla_journal_owner_index` (`journal_entry_id`, `user_id`),
    KEY `jla_debit_owner_entry_index` (`user_id`, `journal_entry_id`, `debit_line_id`),
    KEY `jla_credit_owner_entry_index` (`user_id`, `journal_entry_id`, `credit_line_id`),
    CONSTRAINT `jla_distinct_lines_check` CHECK (`debit_line_id` <> `credit_line_id`),
    CONSTRAINT `jla_positive_amount_check` CHECK (`amount` > 0),
    CONSTRAINT `jla_category_check` CHECK (`category` IS NULL OR CAST(`category` AS BINARY) IN ('operating', 'investing', 'financing')),
    CONSTRAINT `jla_journal_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `jla_debit_owner_entry_fk` FOREIGN KEY (`user_id`, `journal_entry_id`, `debit_line_id`) REFERENCES `journal_lines` (`user_id`, `journal_entry_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `jla_credit_owner_entry_fk` FOREIGN KEY (`user_id`, `journal_entry_id`, `credit_line_id`) REFERENCES `journal_lines` (`user_id`, `journal_entry_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_line_allocations')) {
            return;
        }
        if (DB::table('journal_line_allocations')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty cash-flow allocations.');
        }
        Schema::drop('journal_line_allocations');
    }
};
