<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_periods')) {
            throw new RuntimeException('Accounting table accounting_periods already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `accounting_periods` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'open',
    `first_closed_at` DATETIME(6) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    KEY `ap_owner_dates_index` (`user_id`, `start_date`, `end_date`),
    KEY `ap_owner_status_start_index` (`user_id`, `status`, `start_date`),
    CONSTRAINT `ap_dates_check` CHECK (`start_date` <= `end_date`),
    CONSTRAINT `ap_status_check` CHECK (CAST(`status` AS BINARY) IN ('open', 'closed')),
    CONSTRAINT `ap_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('accounting_periods')) {
            return;
        }
        if (DB::table('accounting_periods')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty accounting table accounting_periods.');
        }
        Schema::drop('accounting_periods');
    }
};
