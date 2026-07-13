<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create User
        $user = User::firstOrCreate(
            ['email' => 'mobile@cilupbah.id'],
            [
                'name' => 'Mobile Tester',
                'password' => Hash::make('password123'),
            ]
        );
        
        try {
            $user->assignRole('owner');
        } catch (\Exception $e) {}

        // 2. Create Location
        $location = \Modules\Warehouse\Models\Location::firstOrCreate(
            ['code' => 'WH-MOB'],
            [
                'location_name' => 'Gudang Mobile',
                'type' => 'WAREHOUSE',
                'is_active' => true,
            ]
        );

        // 3. Create Bin
        $bin = \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            ['bin_final_code' => 'WH-MOB-A1'],
            [
                'location_id' => $location->id,
                'bin_code' => 'A1',
                'is_inbound' => false,
                'is_outbound' => false,
            ]
        );

        // 4. Create Product & Variant
        $product = \Modules\Product\Models\Product::firstOrCreate(
            ['name' => 'Baju Testing Mobile'],
            [
                'archetype' => 'SIMPLE',
                'is_active' => true,
            ]
        );

        $variant = \Modules\Product\Models\ProductVariant::firstOrCreate(
            ['sku' => 'SKU-MOB-01'],
            [
                'product_id' => $product->id,
                'barcode' => '888000111222',
                'name' => 'Baju Testing Mobile (All Size)',
                'is_active' => true,
            ]
        );

        // 5. Create Inbound Assignment
        $inbound = \Modules\Inbound\Models\Inbound::firstOrCreate(
            ['transaction_number' => 'INB-MOB-001'],
            [
                'type' => 'PURCHASE_ORDER',
                'status' => 'IN_PROGRESS',
                'location_id' => $location->id,
                'total_items' => 1,
                'total_expected_qty' => 50,
                'created_by' => $user->id,
            ]
        );

        \Modules\Inbound\Models\InboundItem::firstOrCreate(
            ['inbound_id' => $inbound->id, 'item_id' => $variant->id],
            [
                'expected_qty' => 50,
                'received_qty' => 0,
            ]
        );
        
        $this->command->info('Mobile Demo Seeder completed successfully!');
    }
}
