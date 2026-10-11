<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_transaction_journals')) {
            throw new RuntimeException('Operational transaction links already exist; inspect schema and migration history.');
        }
        foreach ([
            'transactions' => ['id', 'user_id'],
            'journal_entries' => ['id', 'user_id'],
            'operational_account_mappings' => ['account_id', 'user_id'],
            'operational_category_mappings' => ['category_id', 'user_id'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table) || ! collect(Schema::getIndexes($table))->contains(
                fn (array $index) => $index['unique'] && $index['columns'] === $columns
            )) {
                throw new RuntimeException("Missing referenceable owner key on {$table}.");
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `operational_transaction_journals` (
    `transaction_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `category_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`transaction_id`),
    UNIQUE KEY `otj_journal_unique` (`journal_entry_id`),
    KEY `otj_transaction_owner_index` (`transaction_id`, `user_id`),
    KEY `otj_journal_owner_index` (`journal_entry_id`, `user_id`),
    KEY `otj_account_owner_index` (`account_id`, `user_id`),
    KEY `otj_category_owner_index` (`category_id`, `user_id`),
    CONSTRAINT `otj_transaction_owner_fk` FOREIGN KEY (`transaction_id`, `user_id`) REFERENCES `transactions` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `otj_journal_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `otj_account_owner_fk` FOREIGN KEY (`account_id`, `user_id`) REFERENCES `operational_account_mappings` (`account_id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `otj_category_owner_fk` FOREIGN KEY (`category_id`, `user_id`) REFERENCES `operational_category_mappings` (`category_id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_transaction_journals')) {
            return;
        }
        if (DB::table('operational_transaction_journals')->exists()) {
            throw new RuntimeException('Refusing to drop non-empty operational transaction links.');
        }
        Schema::drop('operational_transaction_journals');
    }
};
