<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->dropForeign(['inventory_transfer_id']);
        });

        $tables = [
            'inventories' => ['id'],
            'inventory_movements' => ['id'],
            'inventory_transfers' => ['id'],
            'inventory_transfer_items' => ['id', 'inventory_transfer_id'],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::getDriverName() !== 'sqlite') {
                    if ($column === 'id') {
                        DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
                    }
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE UUID USING LPAD({$column}::text, 32, '0')::uuid");
                }
            }
        }

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->foreign('inventory_transfer_id')->references('id')->on('inventory_transfers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
    }
};
