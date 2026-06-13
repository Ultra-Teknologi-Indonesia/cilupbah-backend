<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        $tables = [
            'orders' => ['id'],
            'order_items' => ['id', 'order_id'],
            'sales_returns' => ['order_id'],
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

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {

    }
};
