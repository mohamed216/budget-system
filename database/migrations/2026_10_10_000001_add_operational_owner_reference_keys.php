<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'accounts' => 'accounts_id_owner_unique',
        'categories' => 'categories_id_owner_unique',
        'transactions' => 'transactions_id_owner_unique',
        'journal_entries' => 'je_phase3_id_owner_unique',
        'chart_of_accounts' => 'coa_phase3_id_owner_unique',
    ];

    public function up(): void
    {
        $missing = [];
        // Preflight every parent before the first non-transactional MySQL ALTER.
        foreach (self::KEYS as $table => $name) {
            $missing[$table] = ! $this->inspect($table, $name);
        }

        foreach ($missing as $table => $required) {
            if ($required) {
                DB::statement("ALTER TABLE `{$table}` ADD UNIQUE KEY `".self::KEYS[$table].'` (`id`, `user_id`)');
            }
        }
    }

    public function down(): void
    {
        foreach (['operational_account_mappings', 'operational_category_mappings',
            'operational_transaction_journals', 'operational_accounting_integrations'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Refusing to remove owner reference keys while {$table} exists.");
            }
        }
        foreach (self::KEYS as $table => $name) {
            $this->inspect($table, $name);
        }
        foreach (self::KEYS as $table => $name) {
            if (collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            }
        }
    }

    private function inspect(string $table, string $name): bool
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Missing parent table {$table}; no owner key was changed.");
        }
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        foreach (['id', 'user_id'] as $column) {
            if (! isset($columns[$column]) || strtolower($columns[$column]['type']) !== 'bigint unsigned') {
                throw new RuntimeException("Incompatible {$table}.{$column}; expected BIGINT UNSIGNED.");
            }
        }
        if ($columns['id']['nullable'] || $columns['id']['auto_increment'] !== true
            || $columns['user_id']['nullable'] !== in_array($table, ['accounts', 'categories', 'transactions'], true)) {
            throw new RuntimeException("Incompatible owner key columns on {$table}.");
        }

        $equivalent = false;
        foreach (Schema::getIndexes($table) as $index) {
            $matches = $index['columns'] === ['id', 'user_id'] && $index['unique'];
            if ($index['name'] === $name && ! $matches) {
                throw new RuntimeException("Incompatible index {$name}; refusing to replace it.");
            }
            $equivalent = $equivalent || $matches;
        }

        return $equivalent;
    }
};
