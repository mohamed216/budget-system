<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts') || Schema::hasColumn('chart_of_accounts', 'cash_role')) {
            throw new RuntimeException('Expected chart_of_accounts without cash_role; inspect schema and migration history.');
        }

        // NULL is deliberately the only initial value, including for existing rows.
        DB::statement(<<<'SQL'
ALTER TABLE `chart_of_accounts`
    ADD COLUMN `cash_role` VARCHAR(16) NULL AFTER `is_active`,
    ADD CONSTRAINT `coa_cash_role_check` CHECK (`cash_role` IS NULL OR CAST(`cash_role` AS BINARY) IN ('non_cash', 'cash', 'cash_equivalent')),
    ADD CONSTRAINT `coa_cash_asset_check` CHECK (`cash_role` IS NULL OR CAST(`cash_role` AS BINARY) = 'non_cash' OR CAST(`type` AS BINARY) = 'asset')
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chart_of_accounts', 'cash_role')) {
            return;
        }
        if (DB::table('chart_of_accounts')->whereNotNull('cash_role')->exists()) {
            throw new RuntimeException('Refusing to remove reviewed chart account cash roles.');
        }
        DB::statement('ALTER TABLE `chart_of_accounts` DROP CHECK `coa_cash_asset_check`, DROP CHECK `coa_cash_role_check`, DROP COLUMN `cash_role`');
    }
};
