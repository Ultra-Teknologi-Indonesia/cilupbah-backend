<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop foreign keys
        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->dropForeign(['sales_return_id']);
        });

        // 2. Alter column types to VARCHAR(32)
        $tables = [
            'sales_returns' => ['id'],
            'sales_return_items' => ['id', 'sales_return_id'],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                if ($column === 'id') {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
                }
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE VARCHAR(32) USING {$column}::VARCHAR(32)");
            }
        }

        // 3. Re-add foreign keys
        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->foreign('sales_return_id')->references('id')->on('sales_returns')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
    }
};
