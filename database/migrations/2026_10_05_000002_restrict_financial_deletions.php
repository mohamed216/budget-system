<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceDeleteRule('RESTRICT');
    }

    public function down(): void
    {
        $this->replaceDeleteRule('CASCADE');
    }

    private function replaceDeleteRule(string $rule): void
    {
        $database = DB::connection()->getDatabaseName();
        $usedNames = array_map(fn ($row) => strtolower($row->CONSTRAINT_NAME), DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?',
            [$database]
        ));
        foreach (['transactions' => ['account_id' => 'accounts', 'category_id' => 'categories'], 'budgets' => ['category_id' => 'categories']] as $table => $references) {
            $foreignKeys = collect(Schema::getForeignKeys($table));
            $changes = [];
            foreach ($references as $column => $parent) {
                $matching = $foreignKeys->filter(fn (array $key) => in_array($column, $key['columns'], true))->values();
                $foreignKey = $matching->first();
                if ($matching->count() !== 1 || $foreignKey['columns'] !== [$column]
                    || $foreignKey['foreign_table'] !== $parent || $foreignKey['foreign_columns'] !== ['id']
                    || $foreignKey['foreign_schema'] !== $database) {
                    throw new RuntimeException("Missing or incompatible foreign key for {$table}.{$column}; expected {$parent}.id in this database.");
                }
                $updateRule = strtoupper($foreignKey['on_update']);
                $deleteRule = strtoupper($foreignKey['on_delete']);
                if (! in_array($updateRule, ['RESTRICT', 'NO ACTION', 'CASCADE', 'SET NULL'], true)
                    || ! in_array($deleteRule, ['RESTRICT', 'NO ACTION', 'CASCADE'], true)) {
                    throw new RuntimeException("Unexpected referential rules for {$table}.{$column}; review the schema.");
                }
                if ($deleteRule === $rule) {
                    continue;
                }

                // Distinct schema-wide names avoid collisions, including custom existing names.
                $base = substr("{$table}_{$column}", 0, 35).'_'.substr(hash('sha256', $foreignKey['name'].$rule), 0, 16);
                $name = $base;
                for ($suffix = 1; in_array(strtolower($name), $usedNames, true); $suffix++) {
                    $name = $base.'_'.$suffix;
                }
                $usedNames[] = strtolower($name);
                $oldName = str_replace('`', '``', $foreignKey['name']);
                $changes[] = "DROP FOREIGN KEY `{$oldName}`";
                $changes[] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`) ON DELETE {$rule} ON UPDATE {$updateRule}";
            }
            if ($changes !== []) {
                // Atomic per table; already completed tables are safe to retry.
                DB::statement("ALTER TABLE `{$table}` ".implode(', ', $changes));
            }
        }
    }
};
