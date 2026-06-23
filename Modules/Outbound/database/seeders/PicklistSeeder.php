<?php

namespace Modules\Outbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\ProductVariant;
use App\Models\User;

class PicklistSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Location::where('location_code', 'WH-MAIN')->first();

        if (!$warehouse) {
            $this->command->warn('PicklistSeeder: WH-MAIN tidak ditemukan. Jalankan InboundDatabaseSeeder terlebih dahulu.');
            return;
        }

        $bin1 = LocationBin::where('location_id', $warehouse->id)
            ->where('bin_final_code', 'F1-R1-C1-B1')
            ->first();

        $bin2 = LocationBin::where('location_id', $warehouse->id)
            ->where('bin_final_code', 'F1-R1-C2-B1')
            ->first();

        if (!$bin1 || !$bin2) {
            $this->command->warn('PicklistSeeder: Location bins tidak lengkap.');
            return;
        }

        $laptop = ProductVariant::where('sku', 'LAPTOP-001-8GB')->first();
        $mouse = ProductVariant::where('sku', 'MOUSE-001-BLK')->first();
        $keyboard = ProductVariant::where('sku', 'KBD-001-RED')->first();

        if (!$laptop || !$mouse || !$keyboard) {
            $this->command->warn('PicklistSeeder: Product variants tidak lengkap.');
            return;
        }

        $staff = User::firstOrCreate(
            ['email' => 'staff-gudang@cilupbah.id'],
            [
                'name' => 'Staff Gudang',
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
            ]
        );

        if (!$staff->hasRole('warehouse_staff')) {
            try {
                $staff->assignRole('warehouse_staff');
            } catch (\Exception $e) {

            }
        }

        $variants = [$laptop, $mouse, $keyboard];
        $bins = [$bin1, $bin2];

        $customerNames = [
            'Pelanggan 1', 'Toko ABC', 'CV Maju Jaya', 'PT Sentosa', 'Pelanggan 5',
            'Toko Elektronik Baru', 'UD Makmur', 'Ibu Sari', 'Bapak Rudi', 'Toko Online XYZ',
            'CV Berkah Abadi', 'PT Nusantara Digital', 'Pelanggan 13', 'Toko Cahaya', 'UD Sejahtera',
        ];

        $salesOrders = [];
        $salesOrderItems = [];

        for ($s = 1; $s <= 15; $s++) {
            $seq = str_pad($s, 4, '0', STR_PAD_LEFT);
            $dayOffset = (int) (($s - 1) / 2);
            $createdAt = now()->subDays($dayOffset)->subMinutes($s * 11);

            $itemCount = ($s % 3) + 1; 
            $subTotal = 0;
            $orderItemsData = [];

            for ($j = 0; $j < $itemCount; $j++) {
                $variant = $variants[($s + $j) % count($variants)];
                $qty = ($s + $j) % 5 + 1;
                $price = match ($variant->sku) {
                    'LAPTOP-001-8GB' => 8500000,
                    'MOUSE-001-BLK' => 150000,
                    'KBD-001-RED' => 350000,
                    default => 100000,
                };
                $amount = $price * $qty;
                $subTotal += $amount;

                $orderItemsData[] = [
                    'variant' => $variant,
                    'qty' => $qty,
                    'price' => $price,
                    'amount' => $amount,
                ];
            }

            $order = SalesOrder::firstOrCreate(
                ['salesorder_no' => "SO-SEED-{$seq}"],
                [
                    'customer_name' => $customerNames[$s - 1],
                    'transaction_date' => $createdAt,
                    'sub_total' => $subTotal,
                    'grand_total' => $subTotal,
                    'status' => 'reserved',
                    'is_paid' => true,
                    'location_id' => $warehouse->id,
                    'source' => 'marketplace',
                    'shipping_full_name' => $customerNames[$s - 1],
                    'shipping_phone' => '08' . rand(1000000000, 9999999999),
                    'shipping_address' => "Jl. Contoh No. {$s}, Kota Bandung",
                    'shipping_city' => 'Bandung',
                    'shipping_province' => 'Jawa Barat',
                    'shipping_post_code' => '40' . str_pad($s, 3, '0', STR_PAD_LEFT),
                    'shipping_country' => 'ID',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]
            );

            $createdItems = [];
            foreach ($orderItemsData as $itemData) {
                $orderItem = SalesOrderItem::firstOrCreate(
                    [
                        'order_id' => $order->id,
                        'item_id' => $itemData['variant']->id,
                    ],
                    [
                        'sku' => $itemData['variant']->sku,
                        'description' => $itemData['variant']->sku,
                        'qty_in_base' => $itemData['qty'],
                        'price' => $itemData['price'],
                        'amount' => $itemData['amount'],
                    ]
                );
                $createdItems[] = $orderItem;
            }

            $salesOrders[] = $order;
            $salesOrderItems[$order->id] = $createdItems;
        }

        $statuses = [
            Picklist::STATUS_DRAFT,
            Picklist::STATUS_IN_PROGRESS,
            Picklist::STATUS_COMPLETED,
        ];

        $notes = [
            'Pick pesanan marketplace batch pagi',
            'Pick order express priority',
            'Picking barang promo flash sale',
            'Pick pesanan Tokopedia batch siang',
            'Pick order Shopee reguler',
            'Picking pesanan grosir CV Maju',
            'Pick barang retur tukar baru',
            'Pick pesanan COD area Bandung',
            'Picking batch sore marketplace',
            'Pick order manual walk-in customer',
        ];

        $count = 0;

        for ($i = 1; $i <= 30; $i++) {
            $seq = str_pad($i, 4, '0', STR_PAD_LEFT);
            $dayOffset = (int) (($i - 1) / 3);
            $dayOffset = min($dayOffset, 13); 
            $createdAt = now()->subDays($dayOffset)->subMinutes($i * 7);

            $statusIdx = $i % 3;
            $status = $statuses[$statusIdx];
            $note = $notes[$i % count($notes)];

            $picklistData = [
                'location_id' => $warehouse->id,
                'picker_id' => $staff->id,
                'assigned_by' => 'Owner Cilupbah',
                'status' => $status,
                'notes' => $note,
                'created_by' => 'seeder',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($status === Picklist::STATUS_IN_PROGRESS) {
                $picklistData['started_at'] = $createdAt->copy()->addMinutes(30);
            } elseif ($status === Picklist::STATUS_COMPLETED) {
                $picklistData['started_at'] = $createdAt->copy()->addMinutes(15);
                $picklistData['completed_at'] = $createdAt->copy()->addHours(2);
            }

            $picklist = Picklist::firstOrCreate(
                ['picklist_no' => "PK-SEED-{$seq}"],
                $picklistData
            );

            $orderIdx1 = ($i - 1) % count($salesOrders);
            $orderIdx2 = ($i) % count($salesOrders);
            $assignedOrders = [$salesOrders[$orderIdx1]];
            if ($i % 3 !== 0) {
                $assignedOrders[] = $salesOrders[$orderIdx2];
            }

            foreach ($assignedOrders as $order) {
                $items = $salesOrderItems[$order->id] ?? [];
                foreach ($items as $orderItem) {
                    $variant = ProductVariant::find($orderItem->item_id);
                    $qtyOrdered = $orderItem->qty_in_base;

                    $qtyPicked = match ($status) {
                        Picklist::STATUS_DRAFT => 0,
                        Picklist::STATUS_IN_PROGRESS => (int) ($qtyOrdered * (($i % 4 + 1) * 0.2)),
                        Picklist::STATUS_COMPLETED => $qtyOrdered,
                    };
                    $qtyPicked = min($qtyPicked, $qtyOrdered);

                    $bin = $bins[($i + crc32($orderItem->id)) % count($bins)];

                    PicklistItem::firstOrCreate(
                        [
                            'picklist_id' => $picklist->id,
                            'order_item_id' => $orderItem->id,
                        ],
                        [
                            'order_id' => $order->id,
                            'item_id' => $orderItem->item_id,
                            'sku' => $variant ? $variant->sku : $orderItem->sku,
                            'bin_id' => $bin->id,
                            'qty_ordered' => $qtyOrdered,
                            'qty_picked' => $qtyPicked,
                        ]
                    );
                }
            }

            $count++;
        }

        $this->command->info("PicklistSeeder: {$count} picklist documents created with mixed statuses + items.");
    }
}
