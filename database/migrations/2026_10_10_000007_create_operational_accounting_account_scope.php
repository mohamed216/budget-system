<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_accounting_account_scope')) {
            throw new RuntimeException('Operational accounting account scope already exists; inspect schema and migration history.');
        }
        if (! Schema::hasTable('accounts') || ! collect(Schema::getIndexes('accounts'))->contains(
            fn (array $index) => $index['unique'] && $index['columns'] === ['id', 'user_id']
        )) {
            throw new RuntimeException('Missing referenceable owner key on accounts.');
        }
        if (! Schema::hasTable('users') || ! Schema::hasColumn('operational_accounting_integrations', 'scope_reviewed_at')) {
            throw new RuntimeException('Missing users or integration scope review metadata; refusing to create account scope.');
        }

        // Selection is explicit and independent of mapping completion. Never seed it
        // from balances, mappings, names, transaction history, or the high-water mark.
        DB::statement(<<<'SQL'
CREATE TABLE `operational_accounting_account_scope` (
    `account_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`account_id`),
    UNIQUE KEY `oaas_account_owner_unique` (`account_id`, `user_id`),
    CONSTRAINT `oaas_account_owner_fk` FOREIGN KEY (`account_id`, `user_id`) REFERENCES `accounts` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `oaas_owner_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_accounting_account_scope')) {
            return;
        }
        // A reviewed empty scope still has evidence that must not be discarded.
        if (DB::table('operational_accounting_account_scope')->exists()
            || (Schema::hasTable('operational_accounting_integrations')
                && DB::table('operational_accounting_integrations')->whereNotNull('scope_reviewed_at')->exists())) {
            throw new RuntimeException('Refusing to drop populated or reviewed operational accounting account scope.');
        }
        Schema::drop('operational_accounting_account_scope');
    }
};
