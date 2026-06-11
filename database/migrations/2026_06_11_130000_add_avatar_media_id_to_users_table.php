<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi avatar user ke media terpusat (Spatie media.uuid). Nullable, tanpa FK keras
     * karena media bisa dihapus terpisah; URL di-resolve dinamis lewat UploadService.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('avatar_media_id')->nullable()->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_media_id');
        });
    }
};
