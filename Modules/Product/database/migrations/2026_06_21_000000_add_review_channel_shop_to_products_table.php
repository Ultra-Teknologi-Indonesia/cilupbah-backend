<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('review_channel_shop_id')->nullable()->after('archive_reason');
            $table->foreign('review_channel_shop_id')
                ->references('id')
                ->on('channel_shops')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['review_channel_shop_id']);
            $table->dropColumn('review_channel_shop_id');
        });
    }
};
