<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {

            $table->string('sales_attribute_id')->nullable()->after('channel_seller_sku');
            $table->string('sales_attribute_name')->nullable()->after('sales_attribute_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            $table->dropColumn(['sales_attribute_id', 'sales_attribute_name']);
        });
    }
};
