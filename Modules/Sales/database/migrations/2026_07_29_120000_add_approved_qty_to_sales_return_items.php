<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table) {
            // Qty yang disetujui untuk restock. NULL = perlakukan sebagai = qty (backward-compat).
            $table->integer('approved_qty')->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->dropColumn('approved_qty');
        });
    }
};
