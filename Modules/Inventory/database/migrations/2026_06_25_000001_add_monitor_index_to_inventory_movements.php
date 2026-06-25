<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 0 Monitor Stok (ADR-201): index untuk agregasi penjualan (source ORDER_SHIP)
 * yang dipakai tab Tidak Laku / Paling Laku / Perkiraan Habis (Fase 3),
 * sekaligus mempercepat filter movement per item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['source', 'transaction_date', 'item_id'], 'inv_movements_source_date_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inv_movements_source_date_item_idx');
        });
    }
};
