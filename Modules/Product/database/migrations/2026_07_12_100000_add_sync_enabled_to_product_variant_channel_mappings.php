<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            $table->boolean('sync_enabled')->default(true)->after('synced_stock')->index();
        });
    }

    public function down(): void
    {
        Schema::table('product_variant_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('sync_enabled');
        });
    }
};
