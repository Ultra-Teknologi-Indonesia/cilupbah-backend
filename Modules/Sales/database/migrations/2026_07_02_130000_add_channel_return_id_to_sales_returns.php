<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('channel_return_id')->nullable()->after('source');
            $table->string('channel_shop_id')->nullable()->after('channel_return_id');
            $table->unique(['source', 'channel_return_id'], 'sales_returns_source_channel_return_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique('sales_returns_source_channel_return_unique');
            $table->dropColumn(['channel_return_id', 'channel_shop_id']);
        });
    }
};
