<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'review_channel_shop_id')) {
                $table->dropForeign(['review_channel_shop_id']);
                $table->dropColumn('review_channel_shop_id');
            }
            if (Schema::hasColumn('products', 'pre_review_status')) {
                $table->dropColumn('pre_review_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('review_channel_shop_id')->nullable()->constrained('channel_shops')->nullOnDelete();
            $table->string('pre_review_status')->nullable();
        });
    }
};
