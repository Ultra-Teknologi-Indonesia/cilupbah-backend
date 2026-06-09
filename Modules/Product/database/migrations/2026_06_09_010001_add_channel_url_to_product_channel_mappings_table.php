<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->string('channel_url')->nullable()->after('external_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('channel_url');
        });
    }
};
