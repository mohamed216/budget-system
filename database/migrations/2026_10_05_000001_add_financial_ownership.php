<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->validateUserId();
        foreach (['accounts', 'categories', 'transactions', 'budgets'] as $name) {
            $column = $this->ownershipColumn($name);
            $foreignKey = $this->ownershipForeignKey($name);
            if ($foreignKey !== null && $column === null) {
                throw new RuntimeException("Unexpected ownership constraint without {$name}.user_id.");
            }
            if ($foreignKey !== null) {
                continue;
            }

            $constraint = "{$name}_user_id_foreign";
            $collision = DB::selectOne(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_NAME = ?',
                [DB::connection()->getDatabaseName(), $constraint]
            );
            if ($collision !== null) {
                throw new RuntimeException("Ownership constraint name {$constraint} is already used; review the schema.");
            }

            // One atomic MySQL ALTER per table; a valid column-only partial state is repaired.
            $addColumn = $column === null ? 'ADD COLUMN `user_id` BIGINT UNSIGNED NULL DEFAULT NULL, ' : '';
            DB::statement("ALTER TABLE `{$name}` {$addColumn}ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        foreach (['budgets', 'transactions', 'categories', 'accounts'] as $name) {
            $column = $this->ownershipColumn($name);
            $foreignKey = $this->ownershipForeignKey($name);
            if ($column === null) {
                if ($foreignKey !== null) {
                    throw new RuntimeException("Unexpected ownership constraint without {$name}.user_id.");
                }
                continue;
            }

            // Remove only the validated ownership FK, including custom constraint names.
            $dropForeignKey = $foreignKey === null ? '' : 'DROP FOREIGN KEY `'.str_replace('`', '``', $foreignKey['name']).'`, ';
            DB::statement("ALTER TABLE `{$name}` {$dropForeignKey}DROP COLUMN `user_id`");
        }
    }

    private function validateUserId(): void
    {
        $id = collect(Schema::getColumns('users'))->firstWhere('name', 'id');
        if ($id === null || strtolower($id['type']) !== 'bigint unsigned' || $id['nullable']) {
            throw new RuntimeException('Expected users.id to be a non-null BIGINT UNSIGNED; review ownership compatibility.');
        }
        $indexed = collect(Schema::getIndexes('users'))->contains(fn (array $index) => $index['columns'] === ['id'] && $index['unique']);
        if (! $indexed) {
            throw new RuntimeException('Expected a unique users.id index for ownership foreign keys.');
        }
    }

    private function ownershipColumn(string $table): ?array
    {
        $column = collect(Schema::getColumns($table))->firstWhere('name', 'user_id');
        if ($column !== null && (strtolower($column['type']) !== 'bigint unsigned'
            || ! $column['nullable'] || $column['default'] !== null
            || $column['auto_increment'] || $column['generation'] !== null)) {
            throw new RuntimeException("Incompatible {$table}.user_id; expected nullable BIGINT UNSIGNED with NULL default, without generation or auto-increment.");
        }

        return $column;
    }

    private function ownershipForeignKey(string $table): ?array
    {
        $keys = collect(Schema::getForeignKeys($table))->filter(fn (array $key) => in_array('user_id', $key['columns'], true))->values();
        if ($keys->isEmpty()) {
            return null;
        }
        $key = $keys->first();
        if ($keys->count() !== 1 || $key['columns'] !== ['user_id']
            || $key['foreign_table'] !== 'users' || $key['foreign_columns'] !== ['id']
            || $key['foreign_schema'] !== DB::connection()->getDatabaseName()
            || strtolower($key['on_delete']) !== 'restrict'
            || ! in_array(strtolower($key['on_update']), ['restrict', 'no action'], true)) {
            throw new RuntimeException("Incompatible {$table}.user_id foreign key; expected users.id in this database with ON DELETE RESTRICT and restrictive ON UPDATE.");
        }

        return $key;
    }
};
