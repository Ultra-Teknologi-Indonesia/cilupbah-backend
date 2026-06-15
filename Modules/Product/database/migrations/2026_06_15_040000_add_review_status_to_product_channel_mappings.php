<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Longgarkan enum sync_status agar mendukung status review marketplace
        // (in_review = menunggu approval, rejected = ditolak marketplace).
        DB::statement('ALTER TABLE product_channel_mappings DROP CONSTRAINT IF EXISTS product_channel_mappings_sync_status_check');

        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};
