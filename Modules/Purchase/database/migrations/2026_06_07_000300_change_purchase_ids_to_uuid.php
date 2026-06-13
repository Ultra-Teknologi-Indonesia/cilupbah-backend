<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
        });

        $tables = [
            'purchase_orders' => ['id'],
            'purchase_order_items' => ['id', 'purchase_order_id'],
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

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
    }
};
