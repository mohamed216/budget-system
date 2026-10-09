<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_flow_journal_completions')) {
            throw new RuntimeException('Cash-flow completion table already exists; inspect schema and migration history.');
        }
        DB::statement(<<<'SQL'
CREATE TABLE `cash_flow_journal_completions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    `completed_at` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `cfjc_journal_owner_unique` (`journal_entry_id`, `user_id`),
    CONSTRAINT `cfjc_journal_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cash_flow_journal_completions')) {
            return;
        }
        if (DB::table('cash_flow_journal_completions')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty cash-flow completions.');
        }
        Schema::drop('cash_flow_journal_completions');
    }
};
