<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {

            $table->index(['channel_shop_id', 'external_product_id'], 'pcm_shop_external_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->dropIndex('pcm_shop_external_idx');
        });
    }
};
