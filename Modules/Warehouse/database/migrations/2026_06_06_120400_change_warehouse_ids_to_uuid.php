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
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
        });

        Schema::table('channel_warehouses', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('location_zones', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('location_bins', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['zone_id']);
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['bin_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropForeign(['bin_id']);
        });

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropForeign(['source_location_id']);
            $table->dropForeign(['destination_location_id']);
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->dropForeign(['source_bin_id']);
            $table->dropForeign(['destination_bin_id']);
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->dropForeign(['bin_id']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        // 2. Alter column types to VARCHAR(32)
        $tables = [
            'locations' => ['id'],
            'location_zones' => ['id', 'location_id'],
            'location_bins' => ['id', 'location_id', 'zone_id'],
            'channel_warehouses' => ['location_id'],
            'users' => ['warehouse_id'],
            'inventories' => ['location_id', 'bin_id'],
            'inventory_movements' => ['location_id', 'bin_id'],
            'inventory_transfers' => ['source_location_id', 'destination_location_id'],
            'inventory_transfer_items' => ['source_bin_id', 'destination_bin_id'],
            'inbounds' => ['location_id'],
            'inbound_receipts' => ['bin_id'],
            'purchase_orders' => ['location_id'],
            'sales_returns' => ['location_id'],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                // Drop default sequence if it's the primary key 'id'
                if ($column === 'id') {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
                }
                
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE UUID USING LPAD({$column}::text, 32, '0')::uuid");
            }
        }

        // 3. Re-add foreign keys
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('warehouse_id')->references('id')->on('locations')->nullOnDelete();
        });

        Schema::table('channel_warehouses', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
        });

        Schema::table('location_zones', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
        });

        Schema::table('location_bins', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('zone_id')->references('id')->on('location_zones')->cascadeOnDelete();
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('bin_id')->references('id')->on('location_bins')->nullOnDelete();
        });

        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->foreign('source_location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('destination_location_id')->references('id')->on('locations')->restrictOnDelete();
        });

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->foreign('source_bin_id')->references('id')->on('location_bins')->nullOnDelete();
            $table->foreign('destination_bin_id')->references('id')->on('location_bins')->nullOnDelete();
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
        });

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->foreign('bin_id')->references('id')->on('location_bins')->restrictOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Reverting this is complex and might lose UUID formats back to integers.
        // It's not practically possible to revert UUIDv7 to serial bigint safely.
    }
};
