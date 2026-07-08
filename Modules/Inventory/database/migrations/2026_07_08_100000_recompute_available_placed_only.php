<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menyelaraskan kolom `inventories.available` dengan aturan baru:
 * stok hanya "available" (sellable & pickable) bila SUDAH DITEMPATKAN di bin rak
 * final (bin_id NOT NULL dan location_bins.is_inbound = false). Stok di Bin Inbound
 * atau baris agregat bin_id NULL berstatus "menunggu penempatan" → available = 0.
 *
 * Idempotent: aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Baris yang sudah ditempatkan (rak final): available = max(0, on_hand - reserved).
        DB::statement(<<<'SQL'
            UPDATE inventories AS i
            SET available = GREATEST(0, i.on_hand - i.reserved)
            FROM location_bins AS b
            WHERE b.id = i.bin_id
              AND b.is_inbound = false
        SQL);

        // 2) Baris belum ditempatkan (bin_id NULL atau Bin Inbound): available = 0.
        DB::statement(<<<'SQL'
            UPDATE inventories AS i
            SET available = 0
            WHERE i.bin_id IS NULL
               OR EXISTS (
                   SELECT 1 FROM location_bins AS b
                   WHERE b.id = i.bin_id AND b.is_inbound = true
               )
        SQL);
    }

    public function down(): void
    {
        // Kembalikan ke rumus lama (tanpa memandang penempatan): available = max(0, on_hand - reserved).
        DB::statement('UPDATE inventories SET available = GREATEST(0, on_hand - reserved)');
    }
};
