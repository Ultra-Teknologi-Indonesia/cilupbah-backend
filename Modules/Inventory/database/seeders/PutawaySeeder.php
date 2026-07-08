<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\PutawayItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\ProductVariant;
use App\Models\User;

class PutawaySeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Location::where('location_code', 'WH-MAIN')->first();

        if (!$warehouse) {
            $this->command->warn('PutawaySeeder: WH-MAIN tidak ditemukan. Jalankan InboundDatabaseSeeder terlebih dahulu.');
            return;
        }

        $inboundBin = LocationBin::where('location_id', $warehouse->id)
            ->where('is_inbound', true)
            ->first();

        $bin1 = LocationBin::where('location_id', $warehouse->id)
            ->where('bin_final_code', 'F1-R1-C1-B1')
            ->first();

        $bin2 = LocationBin::where('location_id', $warehouse->id)
            ->where('bin_final_code', 'F1-R1-C2-B1')
            ->first();

        if (!$inboundBin || !$bin1 || !$bin2) {
            $this->command->warn('PutawaySeeder: Location bins tidak lengkap.');
            return;
        }

        $laptop = ProductVariant::where('sku', 'LAPTOP-001-8GB')->first();
        $mouse = ProductVariant::where('sku', 'MOUSE-001-BLK')->first();
        $keyboard = ProductVariant::where('sku', 'KBD-001-RED')->first();

        if (!$laptop || !$mouse || !$keyboard) {
            $this->command->warn('PutawaySeeder: Product variants tidak lengkap.');
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
        $sources = ['INBOUND', 'MANUAL', 'TRANSFER'];
        $statuses = [
            Putaway::STATUS_NOT_STARTED,
            Putaway::STATUS_IN_PROGRESS,
            Putaway::STATUS_COMPLETED,
        ];
        $notes = [
            'Putaway dari PO laptop dan mouse',
            'Putaway keyboard konsinyasi',
            'Putaway manual mouse batch baru',
            'Restok dari retur pelanggan',
            'Penempatan barang promo',
            'Putaway dari transfer cabang',
            'Penyimpanan sementara zona B',
            'Putaway express prioritas tinggi',
            'Penempatan ulang stok overstock',
            'Putaway dari inbound pagi',
        ];

        $count = 0;

        for ($i = 1; $i <= 40; $i++) {
            $seq = str_pad($i, 4, '0', STR_PAD_LEFT);
            $dayOffset = (int) (($i - 1) / 3);
            $createdAt = now()->subDays($dayOffset)->subMinutes($i * 7);

            $statusIdx = $i % 3;
            $status = $statuses[$statusIdx];
            $source = $sources[$i % 3];
            $note = $notes[$i % count($notes)];

            $putawayData = [
                'location_id' => $warehouse->id,
                'source_type' => $source,
                'status' => $status,
                'assigned_to' => $staff->id,
                'assigned_by' => 'Owner Cilupbah',
                'notes' => $note,
                'created_by' => $staff->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($status === Putaway::STATUS_IN_PROGRESS) {
                $putawayData['started_at'] = $createdAt->copy()->addMinutes(30);
            } elseif ($status === Putaway::STATUS_COMPLETED) {
                $putawayData['started_at'] = $createdAt->copy()->addMinutes(15);
                $putawayData['completed_at'] = $createdAt->copy()->addHours(2);
            }

            $putaway = Putaway::firstOrCreate(
                ['putaway_no' => "PUT-SEED-{$seq}"],
                $putawayData
            );

            $itemCount = ($i % 3) + 1;
            for ($j = 0; $j < $itemCount; $j++) {
                $variant = $variants[($i + $j) % count($variants)];
                $qty = (($i + $j) % 5 + 1) * 10;

                $putawayQty = match ($status) {
                    Putaway::STATUS_NOT_STARTED => 0,
                    Putaway::STATUS_IN_PROGRESS => (int) ($qty * (($i % 4 + 1) * 0.2)),
                    Putaway::STATUS_COMPLETED => $qty,
                };
                $putawayQty = min($putawayQty, $qty);

                $destBin = $status === Putaway::STATUS_NOT_STARTED
                    ? null
                    : $bins[($i + $j) % count($bins)];

                PutawayItem::firstOrCreate(
                    ['putaway_id' => $putaway->id, 'item_id' => $variant->id],
                    [
                        'source_bin_id' => $inboundBin->id,
                        'destination_bin_id' => $destBin?->id,
                        'qty' => $qty,
                        'putaway_qty' => $putawayQty,
                    ]
                );

                $remaining = $qty - $putawayQty;
                if ($remaining > 0) {
                    Inventory::firstOrCreate(
                        [
                            'item_id' => $variant->id,
                            'location_id' => $warehouse->id,
                            'bin_id' => $inboundBin->id,
                            'batch_no' => '',
                            'serial_no' => '',
                        ],
                        [
                            'on_hand' => 0,
                            'on_order' => 0,
                            'reserved' => 0,
                            'available' => 0,
                        ]
                    );

                    $inv = Inventory::where('item_id', $variant->id)
                        ->where('location_id', $warehouse->id)
                        ->where('bin_id', $inboundBin->id)
                        ->first();
                    if ($inv->on_hand < $remaining) {
                        $inv->on_hand = $remaining;
                        $inv->available = $remaining;
                        $inv->save();
                    }
                }
            }

            $count++;
        }

        $this->command->info("PutawaySeeder: {$count} putaway documents created with mixed statuses + items.");
    }
}
