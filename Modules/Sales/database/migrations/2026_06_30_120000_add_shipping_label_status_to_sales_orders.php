<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'shipping_label_status')) {
                $table->enum('shipping_label_status', ['not_ready', 'preparing', 'ready', 'failed'])
                    ->nullable()
                    ->after('shipping_provider');
                $table->index('shipping_label_status', 'sales_orders_shipping_label_status_idx');
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_label_doc_type')) {
                $table->string('shipping_label_doc_type', 64)
                    ->nullable()
                    ->after('shipping_label_status');
            }

            if (! Schema::hasColumn('sales_orders', 'shipping_label_prepared_at')) {
                $table->timestamp('shipping_label_prepared_at')
                    ->nullable()
                    ->after('shipping_label_doc_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'shipping_label_prepared_at')) {
                $table->dropColumn('shipping_label_prepared_at');
            }

            if (Schema::hasColumn('sales_orders', 'shipping_label_doc_type')) {
                $table->dropColumn('shipping_label_doc_type');
            }

            if (Schema::hasColumn('sales_orders', 'shipping_label_status')) {
                $table->dropIndex('sales_orders_shipping_label_status_idx');
                $table->dropColumn('shipping_label_status');
            }
        });
    }
};
