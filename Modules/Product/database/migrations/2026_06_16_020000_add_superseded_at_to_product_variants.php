<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase D: varian yang "digantikan" saat ekspansi jenis varian (mis. Warna →
     * Warna+Ukuran) di-soft-deprecate, BUKAN dihapus — agar channel-mapping &
     * histori order tetap utuh. superseded_at membedakannya dari nonaktif manual.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
