<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bersihkan duplikat (location_id, bin_final_code) yang sempat tercipta sebelum guard idempoten.
        // Survivor = id terkecil per grup (UUID v7 -> id terkecil = paling awal dibuat).
        // Referensi pada bin duplikat dipindahkan ke survivor agar tidak ada stok/transaksi yang putus.
        if (DB::getDriverName() === 'pgsql') {
            $referencing = [
                'inventories' => ['bin_id'],
                'inbound_receipts' => ['bin_id'],
                'inventory_movements' => ['bin_id'],
                'inventory_transfer_items' => ['source_bin_id', 'destination_bin_id'],
            ];

            foreach ($referencing as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    // Postgres tidak punya MIN(uuid); pakai MIN(id::text) (urutan leksikal hex = urutan UUID v7).
                    DB::statement("
                        UPDATE {$table} t
                        SET {$column} = m.keep_id::uuid
                        FROM (
                            SELECT id, MIN(id::text) OVER (PARTITION BY location_id, bin_final_code) AS keep_id
                            FROM location_bins
                        ) m
                        WHERE t.{$column} = m.id AND m.id::text <> m.keep_id
                    ");
                }
            }

            DB::statement("
                DELETE FROM location_bins lb
                USING (
                    SELECT id, MIN(id::text) OVER (PARTITION BY location_id, bin_final_code) AS keep_id
                    FROM location_bins
                ) m
                WHERE lb.id = m.id AND m.id::text <> m.keep_id
            ");
        }

        Schema::table('location_bins', function (Blueprint $table) {
            $table->unique(['location_id', 'bin_final_code'], 'location_bins_location_final_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('location_bins', function (Blueprint $table) {
            $table->dropUnique('location_bins_location_final_code_unique');
        });
    }
};
