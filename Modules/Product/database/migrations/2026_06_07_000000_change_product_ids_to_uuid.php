<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop foreign keys referencing products or product_variants
        $tablesWithFkToProducts = [
            'product_specifications' => 'product_id',
            'product_variants' => 'product_id',
            'product_media' => 'product_id',
            'product_variation_types' => 'product_id',
            'inventories' => 'item_id',
            'inventory_movements' => 'item_id',
            'purchase_order_items' => 'item_id',
            'inventory_transfer_items' => 'item_id',
            'sales_return_items' => 'item_id',
        ];

        $tablesWithFkToVariants = [
            'variant_options' => 'variant_id',
            'product_wholesale_prices' => 'variant_id',
            'product_bundles' => ['bundle_variant_id', 'component_variant_id'],
            'product_media' => 'variant_id',
        ];

        foreach ($tablesWithFkToProducts as $table => $column) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($column) {
                    $t->dropForeign([$column]);
                });
            }
        }

        foreach ($tablesWithFkToVariants as $table => $columns) {
            if (Schema::hasTable($table)) {
                $columns = (array) $columns;
                foreach ($columns as $column) {
                    Schema::table($table, function (Blueprint $t) use ($column) {
                        $t->dropForeign([$column]);
                    });
                }
            }
        }

        // 2. Alter column types to VARCHAR(32)
        $tablesToAlter = [
            'products' => ['id'],
            'product_variants' => ['id', 'product_id'],
            'product_specifications' => ['product_id'],
            'variant_options' => ['variant_id'],
            'product_wholesale_prices' => ['variant_id'],
            'product_bundles' => ['bundle_variant_id', 'component_variant_id'],
            'product_media' => ['product_id', 'variant_id'],
            'product_variation_types' => ['product_id'],
            'inventories' => ['item_id'],
            'inventory_movements' => ['item_id'],
            'inventory_transfer_items' => ['item_id'],
            'purchase_order_items' => ['item_id'],
            'sales_return_items' => ['item_id'],
            'inbound_items' => ['item_id'],
            'order_items' => ['item_id'],
        ];

        foreach ($tablesToAlter as $table => $columns) {
            if (Schema::hasTable($table)) {
                foreach ($columns as $column) {
                    if (DB::getDriverName() !== 'sqlite') {
                        if ($column === 'id') {
                            DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
                        }
                        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE UUID USING LPAD({$column}::text, 32, '0')::uuid");
                    }
                }
            }
        }

        // 3. Re-add foreign keys
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        Schema::table('product_specifications', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        Schema::table('product_media', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });
        Schema::table('product_variation_types', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
        Schema::table('variant_options', function (Blueprint $table) {
            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });
        Schema::table('product_wholesale_prices', function (Blueprint $table) {
            $table->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->foreign('bundle_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('component_variant_id')->references('id')->on('product_variants')->cascadeOnDelete();
        });

        // Other modules
        Schema::table('inventories', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('product_variants');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('product_variants');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('product_variants');
        });
        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('product_variants');
        });
        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('product_variants');
        });
    }

    public function down(): void
    {
    }
};
