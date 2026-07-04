<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->uuid('internal_store_id')->nullable()->after('channel_shop_id');
            $table->uuid('salesman_id')->nullable()->after('internal_store_id');
            $table->boolean('is_manual')->default(false)->after('salesman_id');
            $table->string('no_ref', 64)->nullable()->after('salesorder_no');
            $table->text('note')->nullable()->after('cancel_reason');
            $table->string('delivery_method', 32)->nullable()->after('shipping_country');
            $table->boolean('is_jubelio_shipment')->default(false)->after('delivery_method');
            $table->decimal('shipping_discount', 12, 2)->default(0)->after('shipping_cost');
            $table->decimal('other_discount', 12, 2)->default(0)->after('total_disc');
            $table->boolean('price_includes_tax')->default(false)->after('other_discount');

            $table->foreign('internal_store_id')
                ->references('id')->on('internal_stores')
                ->nullOnDelete();

            $table->foreign('salesman_id')
                ->references('id')->on('salesmen')
                ->nullOnDelete();

            $table->index('is_manual', 'idx_so_is_manual');
            $table->index('internal_store_id', 'idx_so_internal_store');
            $table->index('salesman_id', 'idx_so_salesman');
            $table->index('delivery_method', 'idx_so_delivery_method');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['internal_store_id']);
            $table->dropForeign(['salesman_id']);

            $table->dropIndex('idx_so_is_manual');
            $table->dropIndex('idx_so_internal_store');
            $table->dropIndex('idx_so_salesman');
            $table->dropIndex('idx_so_delivery_method');

            $table->dropColumn([
                'internal_store_id',
                'salesman_id',
                'is_manual',
                'no_ref',
                'note',
                'delivery_method',
                'is_jubelio_shipment',
                'shipping_discount',
                'other_discount',
                'price_includes_tax',
            ]);
        });
    }
};
