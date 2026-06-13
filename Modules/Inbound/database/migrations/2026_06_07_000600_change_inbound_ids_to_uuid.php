<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('inbound_items', function (Blueprint $table) {
            $table->dropForeign(['inbound_id']);
        });

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->dropForeign(['inbound_item_id']);
        });

        Schema::table('inbound_assignments', function (Blueprint $table) {
            $table->dropForeign(['inbound_id']);
        });

        $tables = [
            'inbounds' => ['id'],
            'inbound_items' => ['id', 'inbound_id'],
            'inbound_receipts' => ['id', 'inbound_item_id'],
            'inbound_assignments' => ['id', 'inbound_id'],
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

        Schema::table('inbound_items', function (Blueprint $table) {
            $table->foreign('inbound_id')->references('id')->on('inbounds')->cascadeOnDelete();
        });

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->foreign('inbound_item_id')->references('id')->on('inbound_items')->cascadeOnDelete();
        });

        Schema::table('inbound_assignments', function (Blueprint $table) {
            $table->foreign('inbound_id')->references('id')->on('inbounds')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
    }
};
