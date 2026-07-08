<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WarehouseDatabaseSeeder extends Seeder
{
    public function run(): void
    {

        if (! DB::table('locations')->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_PUSAT_CODE)->exists()) {
            $this->insertLocationWithDefaultBin([
                'location_code' => \Modules\Warehouse\Models\Location::SYSTEM_PUSAT_CODE,
                'location_name' => 'Gudang Pusat',
                'location_type' => 'Gudang',
                'is_warehouse' => true,
                'is_system' => true,
                'is_locked' => false,
            ]);
        }

        if (! DB::table('locations')->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_KECIL_CODE)->exists()) {
            $this->insertLocationWithDefaultBin([
                'location_code' => \Modules\Warehouse\Models\Location::SYSTEM_KECIL_CODE,
                'location_name' => 'Gudang Kecil',
                'location_type' => 'Gudang',
                'is_warehouse' => true,
                'is_system' => true,
                'is_locked' => false,
            ]);
        }

        if (! DB::table('locations')->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_TRANSIT_CODE)->exists()) {
            $this->insertLocationWithDefaultBin([
                'location_code' => \Modules\Warehouse\Models\Location::SYSTEM_TRANSIT_CODE,
                'location_name' => 'Transit',
                'location_type' => 'Lokasi (Non Gudang)',
                'is_warehouse' => false,
                'is_system' => true,
                'is_locked' => true,
            ]);
        }
    }

    private function insertLocationWithDefaultBin(array $attributes): void
    {
        $locationId = \Ramsey\Uuid\Uuid::uuid7()->toString();

        DB::table('locations')->insert(array_merge([
            'id' => $locationId,
            'address' => null,
            'village_id' => null,
            'post_code' => null,
            'is_multi_origin' => false,
            'default_warehouse_user' => null,
            'is_active' => true,
            'is_fbl' => null,
            'is_tcb' => null,
            'is_fbs' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        DB::table('location_bins')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'location_id' => $locationId,
            'floor_code' => null,
            'row_code' => null,
            'column_code' => null,
            'bin_code' => null,
            'bin_final_code' => 'DEFAULT',
            'is_inbound' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
