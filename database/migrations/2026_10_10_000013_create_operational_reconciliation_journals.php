<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operational_reconciliation_journals')) {
            throw new RuntimeException('Evidence table operational_reconciliation_journals already exists; inspect schema and migration history.');
        }
        $this->requireParent('operational_reconciliation_reviews', ['id', 'user_id']);
        $this->requireParent('journal_entries', ['id', 'user_id']);

        // Foundation only: no seeding, source eligibility guards, or sealing triggers.
        DB::statement(<<<'SQL'
CREATE TABLE `operational_reconciliation_journals` (
    `review_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `journal_entry_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`review_id`, `journal_entry_id`),
    UNIQUE KEY `orj_review_owner_journal_unique` (`review_id`, `user_id`, `journal_entry_id`),
    KEY `orj_journal_owner_index` (`journal_entry_id`, `user_id`),
    CONSTRAINT `orj_review_owner_fk` FOREIGN KEY (`review_id`, `user_id`) REFERENCES `operational_reconciliation_reviews` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `orj_journal_owner_fk` FOREIGN KEY (`journal_entry_id`, `user_id`) REFERENCES `journal_entries` (`id`, `user_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_reconciliation_journals')) {
            return;
        }
        if (DB::table('operational_reconciliation_journals')->exists()) {
            throw new RuntimeException('Refusing to drop populated evidence table operational_reconciliation_journals.');
        }
        foreach (['operational_reconciliation_attributions'] as $dependent) {
            if (Schema::hasTable($dependent)) {
                throw new RuntimeException("Drop dependent evidence table {$dependent} through its guarded migration first.");
            }
        }
        $referenced = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('REFERENCED_TABLE_SCHEMA', DB::getDatabaseName())
            ->where('REFERENCED_TABLE_NAME', 'operational_reconciliation_journals')
            ->where(function ($query) {
                $query->where('TABLE_SCHEMA', '<>', DB::getDatabaseName())
                    ->orWhere('TABLE_NAME', '<>', 'operational_reconciliation_journals');
            })->exists();
        if ($referenced) {
            throw new RuntimeException('External constraints still reference operational_reconciliation_journals; refusing rollback.');
        }
        Schema::drop('operational_reconciliation_journals');
    }

    private function requireParent(string $table, array $key): void
    {
        $engine = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)->value('ENGINE');
        if ($engine !== 'InnoDB' || ! collect(Schema::getIndexes($table))->contains(
            fn (array $index) => $index['unique'] && $index['columns'] === $key
        )) {
            throw new RuntimeException("Missing InnoDB referenceable parent key on {$table}.");
        }
        $columns = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $table)
            ->whereIn('COLUMN_NAME', $key)->get()->keyBy('COLUMN_NAME');
        foreach ($key as $column) {
            $definition = $columns->get($column);
            if ($definition === null || strtolower($definition->COLUMN_TYPE) !== 'bigint unsigned'
                || ($column !== 'user_id' && $definition->IS_NULLABLE !== 'NO')) {
                throw new RuntimeException("Incompatible parent column {$table}.{$column}; expected BIGINT UNSIGNED.");
            }
        }
        // Only legacy operational parent owners may remain nullable.
        if (in_array('user_id', $key, true) && ! in_array($table, ['accounts', 'categories', 'transactions'], true)
            && $columns->get('user_id')?->IS_NULLABLE !== 'NO') {
            throw new RuntimeException("Nullable accounting/evidence parent owner on {$table}.");
        }
    }
};
