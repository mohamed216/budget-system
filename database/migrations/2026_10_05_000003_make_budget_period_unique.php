<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('budgets')->select('category_id', 'month', 'year')
            ->groupBy('category_id', 'month', 'year')->havingRaw('COUNT(*) > 1')->exists();

        if ($duplicates) {
            throw new RuntimeException('Duplicate budget periods exist. Review them before migrating; no budgets were changed.');
        }

        Schema::table('budgets', function (Blueprint $table) {
            $table->unique(['category_id', 'month', 'year'], 'budgets_category_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropUnique('budgets_category_period_unique');
        });
    }
};
