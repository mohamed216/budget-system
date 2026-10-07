<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            throw new RuntimeException('Accounting table journal_entries is missing; apply the accounting foundation first.');
        }
        if (Schema::hasColumn('journal_entries', 'reversal_of_id')) {
            throw new RuntimeException('Journal reversal column already exists; inspect its schema and migration history before retrying.');
        }

        // One MySQL DDL statement keeps the new column and its constraints together.
        DB::statement(<<<'SQL'
ALTER TABLE `journal_entries`
    ADD COLUMN `reversal_of_id` BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY `je_reversal_of_unique` (`reversal_of_id`),
    ADD KEY `je_reversal_owner_index` (`reversal_of_id`, `user_id`),
    ADD CONSTRAINT `je_reversal_owner_fk` FOREIGN KEY (`reversal_of_id`, `user_id`)
        REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasColumn('journal_entries', 'reversal_of_id')) {
            return;
        }
        if (DB::table('journal_entries')->whereNotNull('reversal_of_id')->exists()) {
            throw new RuntimeException('Refusing to remove journal reversal tracking while reversal links exist.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE `journal_entries`
    DROP FOREIGN KEY `je_reversal_owner_fk`,
    DROP INDEX `je_reversal_owner_index`,
    DROP INDEX `je_reversal_of_unique`,
    DROP COLUMN `reversal_of_id`
SQL);
    }
};
