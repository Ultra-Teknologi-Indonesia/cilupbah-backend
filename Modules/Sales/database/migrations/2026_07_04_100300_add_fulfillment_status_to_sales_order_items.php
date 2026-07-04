<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->string('fulfillment_status', 24)->nullable();
            $table->unsignedInteger('short_qty')->nullable();

            $table->index('fulfillment_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_status']);
            $table->dropColumn(['fulfillment_status', 'short_qty']);
        });
    }
};
