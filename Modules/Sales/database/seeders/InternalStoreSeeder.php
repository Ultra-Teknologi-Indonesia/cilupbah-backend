<?php

namespace Modules\Sales\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sales\Models\InternalStore;

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
