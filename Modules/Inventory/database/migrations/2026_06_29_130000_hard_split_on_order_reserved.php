<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard-split metrik operasional (on_order) vs komersial (reserved).
 *
 * Sebelum: order masuk dari channel/manual menumpuk reservasi di field
 *   `reserved` yang juga dipakai ReservedStockService untuk promo manual.
 *   Akibatnya tidak bisa dibedakan "X unit dipesan customer" vs
 *   "X unit dicadangkan event flash sale".
 *
 * Sesudah: order masuk → `on_order += qty`. Reservasi promo manual →
 *   `reserved += qty`. Formula: available = on_hand - on_order - reserved.
 *
 * Migrasi data (per keputusan user): reset ke 0 karena belum ada order
 * di staging. Order in-progress akan re-reserve via reconcile job kalau
 * ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Pastikan kolom on_order ada (defensive — model sudah punya di
        //    $fillable, kalau migrate:fresh sudah include, ini no-op).
        Schema::table('inventories', function (Blueprint $table) {
            if (! Schema::hasColumn('inventories', 'on_order')) {
                $table->integer('on_order')->default(0)->after('on_hand');
            }
        });

        // 2) Reset data lama: order in-progress sebelumnya numpuk di
        //    `reserved`. Karena staging belum ada order produksi, aman
        //    set semua ke 0.
        DB::statement('UPDATE inventories SET on_order = 0, reserved = 0, available = on_hand');

        // 3) Index untuk query availability cepat.
        Schema::table('inventories', function (Blueprint $table) {
            if (! $this->hasIndex('inventories', 'inventories_item_loc_avail_idx')) {
                $table->index(['item_id', 'location_id'], 'inventories_item_loc_avail_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            if ($this->hasIndex('inventories', 'inventories_item_loc_avail_idx')) {
                $table->dropIndex('inventories_item_loc_avail_idx');
            }
        });
        // on_order column dibiarkan; tidak drop karena ada di $fillable model.
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        );
        return count($indexes) > 0;
    }
};
