<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop foreign keys and indexes
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropForeign(['channel_id']);
        });

        Schema::table('channel_warehouses', function (Blueprint $table) {
            // Drop index 'idx_channel_mapping' which contains channel_id
            $table->dropIndex('idx_channel_mapping');
        });

        // 2. Alter column types to VARCHAR(32)
        $tables = [
            'channels' => ['id'],
            'channel_shops' => ['id', 'channel_id'],
            'channel_warehouses' => ['channel_id'],
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

        // 3. Re-add foreign keys and indexes
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
        });

        Schema::table('channel_warehouses', function (Blueprint $table) {
            $table->index(['channel_id', 'store_id', 'channel_location_id'], 'idx_channel_mapping');
        });
    }

    public function down(): void
    {
        // Not implemented to prevent data loss
    }
};
