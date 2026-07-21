<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

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

        throw new RuntimeException(
            'Drop tabel Retur Pembelian tidak bisa di-rollback. Untuk memulihkan: '
            . 'restore backup database, lalu revert commit pencabutan fitur.'
        );
    }
};
