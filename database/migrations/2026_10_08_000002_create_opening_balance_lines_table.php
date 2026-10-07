<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opening_balance_lines')) {
            throw new RuntimeException('Accounting table opening_balance_lines already exists; inspect its schema and migration history before retrying. No existing table was changed.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `opening_balance_lines` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `chart_account_id` BIGINT UNSIGNED NOT NULL,
    `debit` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `credit` DECIMAL(15,2) NOT NULL DEFAULT '0.00',
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `obl_batch_account_unique` (`batch_id`, `chart_account_id`),
    KEY `obl_batch_owner_index` (`batch_id`, `user_id`),
    KEY `obl_account_owner_index` (`chart_account_id`, `user_id`),
    CONSTRAINT `obl_side_check` CHECK ((`debit` > 0 AND `credit` = 0) OR (`credit` > 0 AND `debit` = 0)),
    CONSTRAINT `obl_batch_owner_fk` FOREIGN KEY (`batch_id`, `user_id`) REFERENCES `opening_balance_batches` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `obl_account_owner_fk` FOREIGN KEY (`chart_account_id`, `user_id`) REFERENCES `chart_of_accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('opening_balance_lines')) {
            return;
        }
        if (DB::table('opening_balance_lines')->exists()
            || (Schema::hasTable('opening_balance_batches') && DB::table('opening_balance_batches')->exists())) {
            throw new RuntimeException('Refusing to drop opening_balance_lines while opening-balance rows exist.');
        }
        Schema::drop('opening_balance_lines');
    }
};
