<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 2 pencabutan fitur Retur Pembelian (kode dicabut di a3dea41f).
 *
 * Migrasi berjalan otomatis saat deploy, jadi drop table di sini berpotensi
 * menghapus data sebelum sempat diperiksa siapa pun. Karena itu ada guard:
 * kalau ADA SATU BARIS SAJA di tabel mana pun, migrasi berhenti dengan pesan
 * jelas alih-alih menghapus diam-diam.
 *
 * Urutan drop mengikuti arah foreign key (anak dulu, induk terakhir).
 */
return new class extends Migration
{
    /** Anak -> induk. Urutan ini juga dipakai untuk drop. */
    private const TABLES = [
        'purchase_return_settlement_refunds',
        'purchase_return_settlement_bills',
        'purchase_return_settlements',
        'purchase_return_items',
        'purchase_returns',
    ];

    public function up(): void
    {
        $terisi = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $jml = DB::table($table)->count();
            if ($jml > 0) {
                $terisi[$table] = $jml;
            }
        }

        if ($terisi !== []) {
            throw new RuntimeException(
                'Pencabutan Retur Pembelian DIBATALKAN: tabel berikut masih berisi data -> '
                . json_encode($terisi)
                . '. Ekspor atau arsipkan datanya dulu, baru jalankan ulang migrasi ini.'
            );
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Sengaja tidak dibuat reversibel. Mengembalikan struktur berarti
        // menduplikasi definisi dari 2026_06_10_100002 & 2026_06_10_100003,
        // dan menjalankannya tetap tidak mengembalikan isi tabel -- rollback
        // yang "sukses" tapi kosong justru menyesatkan.
        throw new RuntimeException(
            'Drop tabel Retur Pembelian tidak bisa di-rollback. Untuk memulihkan: '
            . 'restore backup database, lalu revert commit pencabutan fitur.'
        );
    }
};
