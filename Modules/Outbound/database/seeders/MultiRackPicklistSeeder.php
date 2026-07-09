<?php

namespace Modules\Outbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\Inventory;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use App\Models\User;

class MultiRackPicklistSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Location::where('location_code', 'WH-MAIN')->first();
        if (!$warehouse) {
            $this->command->warn('WH-MAIN tidak ditemukan.');
            return;
        }

        $keyboard = ProductVariant::where('sku', 'KBD-001-RED')->first();
        $mouse = ProductVariant::where('sku', 'MOUSE-001-BLK')->first();
        $laptop = ProductVariant::where('sku', 'LAPTOP-001-8GB')->first();

        if (!$keyboard || !$mouse || !$laptop) {
            $this->command->warn('Product variants tidak lengkap.');
            return;
        }

        $staff = User::where('email', 'staff-gudang@cilupbah.id')->first();
        if (!$staff) {
            $this->command->warn('staff-gudang@cilupbah.id tidak ditemukan.');
            return;
        }

        // ─── Layout gudang: 2 lantai, 5 rak, 12 bin ───
        //
        // Lantai 1:
        //   R1 = Rak aksesoris kecil (mouse, keyboard)
        //   R2 = Rak elektronik campuran
        //   R3 = Rak laptop & barang besar
        //
        // Lantai 2:
        //   R1 = Rak overflow / stok cadangan
        //   R2 = Rak barang fast-moving
        //
        $binCodes = [
            'F1-R1-C1-B1', 'F1-R1-C2-B1', 'F1-R1-C3-B1',
            'F1-R2-C1-B1', 'F1-R2-C2-B1',
            'F1-R3-C1-B1', 'F1-R3-C2-B1',
            'F2-R1-C1-B1', 'F2-R1-C2-B1',
            'F2-R2-C1-B1', 'F2-R2-C2-B1', 'F2-R2-C3-B1',
        ];

        $bins = [];
        foreach ($binCodes as $code) {
            $bins[$code] = LocationBin::firstOrCreate(
                ['location_id' => $warehouse->id, 'bin_final_code' => $code],
                ['is_inbound' => false, 'max_qty' => 200]
            );
        }

        // ─── Persebaran stok ───
        //
        // Keyboard (KBD-001-RED) — total tersedia: 84
        //   F1-R1-C1-B1: 20   (rak aksesoris utama)
        //   F1-R1-C2-B1: 15
        //   F1-R2-C1-B1: 12   (rak campuran)
        //   F2-R1-C1-B1: 25   (cadangan lantai 2)
        //   F2-R2-C1-B1: 12   (fast-moving lantai 2)
        //
        $this->setInventory($keyboard->id, $warehouse->id, $bins['F1-R1-C1-B1']->id, 20);
        $this->setInventory($keyboard->id, $warehouse->id, $bins['F1-R1-C2-B1']->id, 15);
        $this->setInventory($keyboard->id, $warehouse->id, $bins['F1-R2-C1-B1']->id, 12);
        $this->setInventory($keyboard->id, $warehouse->id, $bins['F2-R1-C1-B1']->id, 25);
        $this->setInventory($keyboard->id, $warehouse->id, $bins['F2-R2-C1-B1']->id, 12);

        // Mouse (MOUSE-001-BLK) — total tersedia: 96
        //   F1-R1-C1-B1: 30   (rak aksesoris utama, beda shelf)
        //   F1-R1-C3-B1: 18
        //   F1-R2-C2-B1: 10   (rak campuran)
        //   F2-R1-C2-B1: 20   (cadangan lantai 2)
        //   F2-R2-C2-B1: 18   (fast-moving lantai 2)
        //
        $this->setInventory($mouse->id, $warehouse->id, $bins['F1-R1-C1-B1']->id, 30);
        $this->setInventory($mouse->id, $warehouse->id, $bins['F1-R1-C3-B1']->id, 18);
        $this->setInventory($mouse->id, $warehouse->id, $bins['F1-R2-C2-B1']->id, 10);
        $this->setInventory($mouse->id, $warehouse->id, $bins['F2-R1-C2-B1']->id, 20);
        $this->setInventory($mouse->id, $warehouse->id, $bins['F2-R2-C2-B1']->id, 18);

        // Laptop (LAPTOP-001-8GB) — total tersedia: 38
        //   F1-R2-C1-B1:  5   (rak campuran, barang besar)
        //   F1-R3-C1-B1:  8   (rak laptop utama)
        //   F1-R3-C2-B1:  6
        //   F2-R1-C1-B1:  7   (cadangan lantai 2)
        //   F2-R2-C3-B1: 12   (fast-moving lantai 2)
        //
        $this->setInventory($laptop->id, $warehouse->id, $bins['F1-R2-C1-B1']->id, 5);
        $this->setInventory($laptop->id, $warehouse->id, $bins['F1-R3-C1-B1']->id, 8);
        $this->setInventory($laptop->id, $warehouse->id, $bins['F1-R3-C2-B1']->id, 6);
        $this->setInventory($laptop->id, $warehouse->id, $bins['F2-R1-C1-B1']->id, 7);
        $this->setInventory($laptop->id, $warehouse->id, $bins['F2-R2-C3-B1']->id, 12);

        $this->command->info('Inventory seeded: 12 bin, 5 rak, 2 lantai');

        // ─── Sales Order: pesanan besar dari distributor ───
        $order = SalesOrder::firstOrCreate(
            ['salesorder_no' => 'SO-MULTIRACK-001'],
            [
                'customer_name' => 'PT Distributor Elektronik Nusantara',
                'transaction_date' => now(),
                'sub_total' => 48 * 350000 + 55 * 150000 + 15 * 7500000,
                'grand_total' => 48 * 350000 + 55 * 150000 + 15 * 7500000,
                'status' => 'reserved',
                'is_paid' => true,
                'location_id' => $warehouse->id,
                'source' => 'marketplace',
                'shipping_full_name' => 'PT Distributor Elektronik Nusantara',
                'shipping_phone' => '02129876543',
                'shipping_address' => 'Jl. Industri Raya No. 88, Kawasan MM2100',
                'shipping_city' => 'Bekasi',
                'shipping_province' => 'Jawa Barat',
                'shipping_post_code' => '17530',
                'shipping_country' => 'ID',
            ]
        );

        $soItemKbd = SalesOrderItem::firstOrCreate(
            ['order_id' => $order->id, 'item_id' => $keyboard->id],
            [
                'sku' => 'KBD-001-RED',
                'description' => 'Keyboard Mechanical Keychron K2',
                'qty_in_base' => 48,
                'price' => 350000,
                'amount' => 48 * 350000,
            ]
        );

        $soItemMouse = SalesOrderItem::firstOrCreate(
            ['order_id' => $order->id, 'item_id' => $mouse->id],
            [
                'sku' => 'MOUSE-001-BLK',
                'description' => 'Mouse Logitech MX Master 3S',
                'qty_in_base' => 55,
                'price' => 150000,
                'amount' => 55 * 150000,
            ]
        );

        $soItemLaptop = SalesOrderItem::firstOrCreate(
            ['order_id' => $order->id, 'item_id' => $laptop->id],
            [
                'sku' => 'LAPTOP-001-8GB',
                'description' => 'Laptop ASUS VivoBook 14',
                'qty_in_base' => 15,
                'price' => 7500000,
                'amount' => 15 * 7500000,
            ]
        );

        // ─── Picklist ───
        $picklist = Picklist::firstOrCreate(
            ['picklist_no' => 'PK-MULTIRACK-001'],
            [
                'location_id' => $warehouse->id,
                'picker_id' => $staff->id,
                'assigned_by' => 'Owner Cilupbah',
                'status' => Picklist::STATUS_IN_PROGRESS,
                'notes' => 'Pesanan besar distributor — 3 produk tersebar di 5 rak, 2 lantai',
                'created_by' => $staff->id,
                'started_at' => now(),
            ]
        );

        PicklistItem::firstOrCreate(
            ['picklist_id' => $picklist->id, 'order_item_id' => $soItemKbd->id],
            [
                'order_id' => $order->id,
                'item_id' => $keyboard->id,
                'sku' => 'KBD-001-RED',
                'bin_id' => $bins['F1-R1-C1-B1']->id,
                'qty_ordered' => 48,
                'qty_picked' => 0,
            ]
        );

        PicklistItem::firstOrCreate(
            ['picklist_id' => $picklist->id, 'order_item_id' => $soItemMouse->id],
            [
                'order_id' => $order->id,
                'item_id' => $mouse->id,
                'sku' => 'MOUSE-001-BLK',
                'bin_id' => $bins['F1-R1-C1-B1']->id,
                'qty_ordered' => 55,
                'qty_picked' => 0,
            ]
        );

        PicklistItem::firstOrCreate(
            ['picklist_id' => $picklist->id, 'order_item_id' => $soItemLaptop->id],
            [
                'order_id' => $order->id,
                'item_id' => $laptop->id,
                'sku' => 'LAPTOP-001-8GB',
                'bin_id' => $bins['F1-R3-C1-B1']->id,
                'qty_ordered' => 15,
                'qty_picked' => 0,
            ]
        );

        $this->command->info('');
        $this->command->info('PK-MULTIRACK-001 — pesanan distributor:');
        $this->command->info('┌─────────────────┬────────┬──────────────────────────────────────────────┐');
        $this->command->info('│ SKU             │ Butuh  │ Stok per rak                                 │');
        $this->command->info('├─────────────────┼────────┼──────────────────────────────────────────────┤');
        $this->command->info('│ KBD-001-RED     │    48  │ F1-R1(35) F1-R2(12) F2-R1(25) F2-R2(12)     │');
        $this->command->info('│ MOUSE-001-BLK   │    55  │ F1-R1(48) F1-R2(10) F2-R1(20) F2-R2(18)     │');
        $this->command->info('│ LAPTOP-001-8GB  │    15  │ F1-R2(5)  F1-R3(14) F2-R1(7)  F2-R2(12)     │');
        $this->command->info('└─────────────────┴────────┴──────────────────────────────────────────────┘');
        $this->command->info('');
        $this->command->info('Filter rak:');
        $this->command->info('  F1-R1 → Keyboard + Mouse');
        $this->command->info('  F1-R2 → Keyboard + Mouse + Laptop');
        $this->command->info('  F1-R3 → Laptop saja');
        $this->command->info('  F2-R1 → Keyboard + Mouse + Laptop');
        $this->command->info('  F2-R2 → Keyboard + Mouse + Laptop');
    }

    private function setInventory(string $itemId, string $locationId, string $binId, int $qty): void
    {
        Inventory::updateOrCreate(
            ['item_id' => $itemId, 'location_id' => $locationId, 'bin_id' => $binId],
            ['on_hand' => $qty, 'reserved' => 0, 'available' => $qty]
        );
    }
}
