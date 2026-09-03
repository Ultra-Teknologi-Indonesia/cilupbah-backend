<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_transactions', function (Blueprint $table): void {
            $table->string('external_product_id')->nullable()->after('channel_shop_id');
            $table->index(
                ['channel_shop_id', 'external_product_id', 'state', 'created_at'],
                'download_transactions_single_product_lookup_index',
            );
        });

        DB::statement(
            "CREATE UNIQUE INDEX download_transactions_active_single_product_unique
             ON download_transactions (channel_shop_id, external_product_id)
             WHERE external_product_id IS NOT NULL
               AND state IN ('queued', 'downloading')",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS download_transactions_active_single_product_unique');

        Schema::table('download_transactions', function (Blueprint $table): void {
            $table->dropIndex('download_transactions_single_product_lookup_index');
            $table->dropColumn('external_product_id');
        });
    }
};
