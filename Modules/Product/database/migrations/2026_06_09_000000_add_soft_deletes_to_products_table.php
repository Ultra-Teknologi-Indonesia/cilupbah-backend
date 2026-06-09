<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Soft delete: produk dead stock dihapus dari katalog aktif,
            // namun record tetap ada agar history transaksi tetap dapat ditelusuri (withTrashed).
            $table->softDeletes()->after('archive_reason');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
