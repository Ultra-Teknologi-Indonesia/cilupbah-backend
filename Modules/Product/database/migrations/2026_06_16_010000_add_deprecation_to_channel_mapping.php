<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Edge case kategori channel (Fase 5):
     *  - channel_categories.deprecated_at : kategori yang hilang dari marketplace
     *    (soft-deprecate, JANGAN hapus agar mapping tidak putus).
     *  - category_channel_mappings.is_stale / last_verified_at : penanda mapping
     *    yang menunjuk kategori usang + kapan terakhir diverifikasi.
     */
    public function up(): void
    {
        Schema::table('channel_categories', function (Blueprint $table) {
            $table->timestamp('deprecated_at')->nullable()->after('is_leaf')->index();
        });

        Schema::table('category_channel_mappings', function (Blueprint $table) {
            $table->boolean('is_stale')->default(false)->after('channel_category_id');
            $table->timestamp('last_verified_at')->nullable()->after('is_stale');
        });
    }

    public function down(): void
    {
        Schema::table('channel_categories', function (Blueprint $table) {
            $table->dropColumn('deprecated_at');
        });

        Schema::table('category_channel_mappings', function (Blueprint $table) {
            $table->dropColumn(['is_stale', 'last_verified_at']);
        });
    }
};
