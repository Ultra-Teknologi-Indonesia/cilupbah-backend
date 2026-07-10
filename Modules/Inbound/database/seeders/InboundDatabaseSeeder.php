<?php

namespace Modules\Inbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Inventory\Models\Inventory;
use Modules\Supplier\Models\Supplier;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use App\Models\User;

class InboundDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $systemUser = User::first();

        $warehouse = Location::firstOrCreate(
            ['location_code' => 'WH-MAIN'],
            [
                'location_name' => 'Gudang Utama Cilupbah',
                'location_type' => 'warehouse',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        $warehouse2 = Location::firstOrCreate(
            ['location_code' => 'WH-BRANCH'],
            [
                'location_name' => 'Gudang Cabang Bandung',
                'location_type' => 'warehouse',
                'is_warehouse'  => true,
                'is_active'     => true,
            ]
        );

        LocationBin::firstOrCreate(
            ['location_id' => $warehouse->id, 'is_inbound' => true],
            [
                'floor_code'     => 'F1',
                'row_code'       => 'R0',
                'column_code'    => 'C0',
                'bin_code'       => 'INBOUND',
                'bin_final_code' => 'F1-R0-C0-INBOUND',
            ]
        );

        LocationBin::firstOrCreate(
            ['location_id' => $warehouse->id, 'bin_final_code' => 'F1-R1-C1-B1'],
            [
                'floor_code'  => 'F1',
                'row_code'    => 'R1',
                'column_code' => 'C1',
                'bin_code'    => 'B1',
                'is_inbound'  => false,
            ]
        );

        LocationBin::firstOrCreate(
            ['location_id' => $warehouse->id, 'bin_final_code' => 'F1-R1-C2-B1'],
            [
                'floor_code'  => 'F1',
                'row_code'    => 'R1',
                'column_code' => 'C2',
                'bin_code'    => 'B1',
                'is_inbound'  => false,
            ]
        );

        LocationBin::firstOrCreate(
            ['location_id' => $warehouse2->id, 'is_inbound' => true],
            [
                'floor_code'     => 'F1',
                'row_code'       => 'R0',
                'column_code'    => 'C0',
                'bin_code'       => 'INBOUND',
                'bin_final_code' => 'F1-R0-C0-INBOUND',
            ]
        );

        $categoryId = \DB::table('categories')->insertGetId([
            'name'       => 'Elektronik',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product1 = Product::firstOrCreate(['sku' => 'LAPTOP-001'], [
            'category_id' => $categoryId,
            'name'        => 'Laptop ASUS VivoBook 14',
            'is_active'   => true,
        ]);

        $variant1 = ProductVariant::firstOrCreate(['sku' => 'LAPTOP-001-8GB'], [
            'product_id' => $product1->id,
            'sell_price'  => 7500000,
            'is_active'   => true,
        ]);

        $product2 = Product::firstOrCreate(['sku' => 'MOUSE-001'], [
            'category_id' => $categoryId,
            'name'        => 'Mouse Logitech MX Master 3S',
            'is_active'   => true,
        ]);

        $variant2 = ProductVariant::firstOrCreate(['sku' => 'MOUSE-001-BLK'], [
            'product_id' => $product2->id,
            'sell_price'  => 1299000,
            'is_active'   => true,
        ]);

        $product3 = Product::firstOrCreate(['sku' => 'KBD-001'], [
            'category_id' => $categoryId,
            'name'        => 'Keyboard Mechanical Keychron K2',
            'is_active'   => true,
        ]);

        $variant3 = ProductVariant::firstOrCreate(['sku' => 'KBD-001-RED'], [
            'product_id' => $product3->id,
            'sell_price'  => 1150000,
            'is_active'   => true,
        ]);

        $rackBin = \Modules\Warehouse\Models\LocationBin::firstOrCreate(
            [
                'location_id'    => $warehouse2->id,
                'bin_final_code' => 'SEED-A-1',
            ],
            [
                'floor_code'  => '1',
                'row_code'    => 'A',
                'column_code' => '1',
                'bin_code'    => 'A-1',
                'is_inbound'  => false,
            ]
        );

        foreach ([$variant1, $variant2, $variant3] as $variant) {
            Inventory::firstOrCreate([
                'item_id'     => $variant->id,
                'location_id' => $warehouse2->id,
                'bin_id'      => $rackBin->id,
                'batch_no'    => '',
                'serial_no'   => '',
            ], [
                'on_hand'   => 200,
                'on_order'  => 0,
                'reserved'  => 0,
                'available' => 200,
            ]);
        }

        $supplier = Supplier::firstOrCreate(['code' => 'SUP-ASUS'], [
            'name'           => 'PT ASUS Indonesia',
            'company_name'   => 'PT Asus Technology Indonesia',
            'email'          => 'order@asus.co.id',
            'phone'          => '021-5555555',
            'address'        => 'Jl. TB Simatupang, Jakarta Selatan',
            'city'           => 'Jakarta',
            'contact_person' => 'Budi Hartono',
            'payment_term'   => 'NET30',
            'status'         => 'active',
        ]);

        Supplier::firstOrCreate(['code' => 'SUP-LOGI'], [
            'name'           => 'PT Logitech Indonesia',
            'company_name'   => 'PT Logitech Far East',
            'email'          => 'b2b@logitech.co.id',
            'phone'          => '021-6666666',
            'address'        => 'Jl. Sudirman, Jakarta',
            'city'           => 'Jakarta',
            'contact_person' => 'Andi Wijaya',
            'payment_term'   => 'NET14',
            'status'         => 'active',
        ]);

        $po = PurchaseOrder::firstOrCreate(['po_number' => 'PO-SEED-001'], [
            'supplier_id'   => $supplier->id,
            'location_id'   => $warehouse->id,
            'status'        => PurchaseOrder::STATUS_OPEN,
            'order_date'    => now()->subDays(3),
            'expected_date' => now()->addDays(4),
            'total_amount'  => 75000000 + 25980000,
            'payment_term'  => 'NET30',
            'notes'         => 'PO seed untuk testing inbound',
            'created_by'    => $systemUser?->id ?? 'system',
        ]);

        PurchaseOrderItem::firstOrCreate(
            ['purchase_order_id' => $po->id, 'item_id' => $variant1->id],
            ['qty' => 10, 'received_qty' => 0, 'unit_price' => 7500000, 'subtotal' => 75000000]
        );

        PurchaseOrderItem::firstOrCreate(
            ['purchase_order_id' => $po->id, 'item_id' => $variant2->id],
            ['qty' => 20, 'received_qty' => 0, 'unit_price' => 1299000, 'subtotal' => 25980000]
        );

        $inbound = Inbound::firstOrCreate(['transaction_number' => 'INB-SEED-CONSIGN'], [
            'location_id'      => $warehouse->id,
            'reference_number' => 'CONSIGN-KEYCHRON-001',
            'type'             => Inbound::TYPE_CONSIGNMENT,
            'source_type'      => 'consignment',
            'status'           => Inbound::STATUS_DRAFT,
            'expected_date'    => now()->addDays(2),
            'created_by'       => $systemUser?->id ?? 'system',
        ]);

        InboundItem::firstOrCreate(
            ['inbound_id' => $inbound->id, 'item_id' => $variant3->id],
            ['expected_qty' => 50, 'received_qty' => 0, 'putaway_qty' => 0, 'discrepancy_qty' => 0]
        );

        $this->command->info('Inbound seeder: 2 warehouses, 3 products, 2 suppliers, 1 PO (OPEN), 1 Inbound (DRAFT consignment)');
    }
}
