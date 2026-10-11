<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_accounting_integrations')) {
            throw new RuntimeException('Operational accounting integration states already exist; inspect schema and migration history.');
        }
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Missing users table for owner-level integration state.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `operational_accounting_integrations` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `state` VARCHAR(16) NOT NULL DEFAULT 'legacy',
    `accounting_currency` CHAR(3) NULL,
    `transaction_high_water_id` BIGINT UNSIGNED NULL,
    `reconciliation_reference` VARCHAR(255) NULL,
    `reconciled_at` DATETIME(6) NULL,
    `activated_at` DATETIME(6) NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `oai_state_check` CHECK (CAST(`state` AS BINARY) IN ('legacy', 'reconciling', 'ready', 'active')),
    CONSTRAINT `oai_active_metadata_check` CHECK (CAST(`state` AS BINARY) <> 'active' OR (`accounting_currency` IS NOT NULL AND `transaction_high_water_id` IS NOT NULL AND `reconciliation_reference` IS NOT NULL AND `reconciled_at` IS NOT NULL AND `activated_at` IS NOT NULL)),
    CONSTRAINT `oai_owner_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_accounting_integrations')) {
            return;
        }
        if (DB::table('operational_accounting_integrations')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty operational accounting integration states.');
        }
        Schema::drop('operational_accounting_integrations');
    }
};
