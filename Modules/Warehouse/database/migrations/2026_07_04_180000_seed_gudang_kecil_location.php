<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\Location;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('locations')->where('location_code', Location::SYSTEM_KECIL_CODE)->exists()) {
            return;
        }

        $locationId = Uuid::uuid7()->toString();

        DB::table('locations')->insert([
            'id'                     => $locationId,
            'location_code'          => Location::SYSTEM_KECIL_CODE,
            'location_name'          => 'Gudang Kecil',
            'location_type'          => 'Gudang',
            'address'                => null,
            'village_id'             => null,
            'post_code'              => null,
            'is_warehouse'           => true,
            'is_multi_origin'        => false,
            'default_warehouse_user' => null,
            'is_active'              => true,
            'is_system'              => true,
            'is_locked'              => false,
            'is_fbl'                 => null,
            'is_tcb'                 => null,
            'is_fbs'                 => null,
            'is_pos'                 => false,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::table('location_bins')->insert([
            'id'             => Uuid::uuid7()->toString(),
            'location_id'    => $locationId,
            'floor_code'     => null,
            'row_code'       => null,
            'column_code'    => null,
            'bin_code'       => null,
            'bin_final_code' => 'DEFAULT',
            'is_inbound'     => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->where('is_system', true)
            ->delete();
    }
};
