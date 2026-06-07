<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarehouseDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $locationId = \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString();
        DB::table('locations')->insert([
            'id' => $locationId,
            'location_code' => 'WH-PUSAT',
            'location_name' => 'Gudang Pusat',
            'location_type' => 'Gudang',
            'address' => null,
            'area' => null,
            'city' => null,
            'province' => null,
            'post_code' => null,
            'is_warehouse' => true,
            'is_multi_origin' => false,
            'default_warehouse_user' => null,
            'is_active' => true,
            'is_fbl' => null,
            'is_tcb' => null,
            'is_fbs' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('location_bins')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->getHex()->toString(),
            'location_id' => $locationId,
            'floor_code' => null,
            'row_code' => null,
            'column_code' => null,
            'bin_code' => null,
            'bin_final_code' => 'DEFAULT',
            'max_qty' => 0,
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
