<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('override_price');
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            $table->decimal('override_price', 15, 2)->nullable()->after('synced_stock');
        });
    }
};
