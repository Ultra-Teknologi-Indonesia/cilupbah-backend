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

        $binA = LocationBin::where('location_id', $warehouse->id)->where('bin_final_code', 'F1-R1-C1-B1')->first();
        $binB = LocationBin::where('location_id', $warehouse->id)->where('bin_final_code', 'F1-R1-C2-B1')->first();
        $binC = LocationBin::where('location_id', $warehouse->id)->where('bin_final_code', 'F1-R0-C0-INBOUND')->first();

        if (!$binA || !$binB || !$binC) {
            $this->command->warn('Bins tidak lengkap.');
            return;
        }

        $keyboard = ProductVariant::where('sku', 'KBD-001-RED')->first();
        $mouse = ProductVariant::where('sku', 'MOUSE-001-BLK')->first();

        if (!$keyboard || !$mouse) {
            $this->command->warn('Product variants tidak lengkap.');
            return;
        }

        $staff = User::where('email', 'staff-gudang@cilupbah.id')->first();
        if (!$staff) {
            $this->command->warn('staff-gudang@cilupbah.id tidak ditemukan.');
            return;
        }

        // --- Setup inventory: keyboard spread across 3 racks ---
        // Rak A: 2, Rak B: 3, Rak C: 10
        $this->setInventory($keyboard->id, $warehouse->id, $binA->id, 2);
        $this->setInventory($keyboard->id, $warehouse->id, $binB->id, 3);
        $this->setInventory($keyboard->id, $warehouse->id, $binC->id, 10);

        // Mouse spread: Rak A: 5, Rak B: 8
        $this->setInventory($mouse->id, $warehouse->id, $binA->id, 5);
        $this->setInventory($mouse->id, $warehouse->id, $binB->id, 8);

        $this->command->info('Inventory set: KBD-001-RED => A(2), B(3), C(10) | MOUSE-001-BLK => A(5), B(8)');

        // --- Sales order: 10 keyboard + 12 mouse ---
        $order = SalesOrder::firstOrCreate(
            ['salesorder_no' => 'SO-MULTIRACK-001'],
            [
                'customer_name' => 'Toko Elektronik Jaya',
                'transaction_date' => now(),
                'sub_total' => 10 * 350000 + 12 * 150000,
                'grand_total' => 10 * 350000 + 12 * 150000,
                'status' => 'reserved',
                'is_paid' => true,
                'location_id' => $warehouse->id,
                'source' => 'marketplace',
                'shipping_full_name' => 'Toko Elektronik Jaya',
                'shipping_phone' => '081234567890',
                'shipping_address' => 'Jl. Multi Rak No. 1, Bandung',
                'shipping_city' => 'Bandung',
                'shipping_province' => 'Jawa Barat',
                'shipping_post_code' => '40100',
                'shipping_country' => 'ID',
            ]
        );

        $soItemKbd = SalesOrderItem::firstOrCreate(
            ['order_id' => $order->id, 'item_id' => $keyboard->id],
            [
                'sku' => 'KBD-001-RED',
                'description' => 'Keyboard Mechanical Keychron K2',
                'qty_in_base' => 10,
                'price' => 350000,
                'amount' => 3500000,
            ]
        );

        $soItemMouse = SalesOrderItem::firstOrCreate(
            ['order_id' => $order->id, 'item_id' => $mouse->id],
            [
                'sku' => 'MOUSE-001-BLK',
                'description' => 'Mouse Logitech MX Master 3S',
                'qty_in_base' => 12,
                'price' => 150000,
                'amount' => 1800000,
            ]
        );

        // --- Picklist IN_PROGRESS ---
        $picklist = Picklist::firstOrCreate(
            ['picklist_no' => 'PK-MULTIRACK-001'],
            [
                'location_id' => $warehouse->id,
                'picker_id' => $staff->id,
                'assigned_by' => 'Owner Cilupbah',
                'status' => Picklist::STATUS_IN_PROGRESS,
                'notes' => 'Test multi-rak picking: keyboard di 3 rak, mouse di 2 rak',
                'created_by' => 'seeder',
                'started_at' => now(),
            ]
        );

        // Keyboard: butuh 10, belum pick
        PicklistItem::firstOrCreate(
            ['picklist_id' => $picklist->id, 'order_item_id' => $soItemKbd->id],
            [
                'order_id' => $order->id,
                'item_id' => $keyboard->id,
                'sku' => 'KBD-001-RED',
                'bin_id' => $binA->id,
                'qty_ordered' => 10,
                'qty_picked' => 0,
            ]
        );

        // Mouse: butuh 12, belum pick
        PicklistItem::firstOrCreate(
            ['picklist_id' => $picklist->id, 'order_item_id' => $soItemMouse->id],
            [
                'order_id' => $order->id,
                'item_id' => $mouse->id,
                'sku' => 'MOUSE-001-BLK',
                'bin_id' => $binA->id,
                'qty_ordered' => 12,
                'qty_picked' => 0,
            ]
        );

        $this->command->info('Picklist PK-MULTIRACK-001 created (IN_PROGRESS):');
        $this->command->info('  KBD-001-RED  => butuh 10 | rak A(2) + B(3) + C(10)');
        $this->command->info('  MOUSE-001-BLK => butuh 12 | rak A(5) + B(8)');
    }

    private function setInventory(string $itemId, string $locationId, string $binId, int $qty): void
    {
        Inventory::updateOrCreate(
            ['item_id' => $itemId, 'location_id' => $locationId, 'bin_id' => $binId],
            ['on_hand' => $qty, 'reserved' => 0, 'available' => $qty]
        );
    }
}
