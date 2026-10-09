<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_lines')
            || collect(Schema::getIndexes('journal_lines'))->contains(fn (array $index) => $index['name'] === 'jl_owner_entry_id_unique')) {
            throw new RuntimeException('Expected journal_lines without its cash-flow allocation key; inspect schema and migration history.');
        }
        DB::statement('ALTER TABLE `journal_lines` ADD UNIQUE KEY `jl_owner_entry_id_unique` (`user_id`, `journal_entry_id`, `id`)');
    }

    public function down(): void
    {
        if (! collect(Schema::getIndexes('journal_lines'))->contains(fn (array $index) => $index['name'] === 'jl_owner_entry_id_unique')) {
            return;
        }
        if (Schema::hasTable('journal_line_allocations')) {
            throw new RuntimeException('Drop journal_line_allocations before its supporting journal line key.');
        }
        DB::statement('ALTER TABLE `journal_lines` DROP INDEX `jl_owner_entry_id_unique`');
    }
};
