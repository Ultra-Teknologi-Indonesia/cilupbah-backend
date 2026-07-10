<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->string('store_url')->nullable()->after('shop_name');
            $table->text('consumer_key')->nullable()->after('store_url');
            $table->text('consumer_secret')->nullable()->after('consumer_key');
            $table->text('webhook_secret')->nullable()->after('consumer_secret');
            $table->json('external_webhook_ids')->nullable()->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn([
                'store_url',
                'consumer_key',
                'consumer_secret',
                'webhook_secret',
                'external_webhook_ids',
            ]);
        });
    }
};
