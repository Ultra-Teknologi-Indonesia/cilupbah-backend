<?php

namespace Modules\Sales\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sales\Models\InternalStore;

/**
 * Production-safe seeder untuk master data Toko Internal.
 * Idempotent: match by name; skip kalau sudah ada.
 */
class InternalStoreSeeder extends Seeder
{
    public function run(): void
    {
        $stores = [
            ['code' => 'TKI-0001', 'name' => 'Toko Grosir Reseller Cilupbah'],
        ];

        foreach ($stores as $data) {
            InternalStore::firstOrCreate(
                ['code' => $data['code']],
                [
                    'name'      => $data['name'],
                    'is_active' => true,
                ]
            );
        }
    }
}
