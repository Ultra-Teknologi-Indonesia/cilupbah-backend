<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\app\Models\InventoryTransfer;
use Modules\Inventory\app\Models\InventoryTransferItem;
use Modules\Inventory\app\Models\Inventory;

class TransferTestSeeder extends Seeder
{
    public function run(): void
    {
        $gudangUtama   = '019ecf7e-b91b-70d4-87b9-0b18f4e5e9f0';
        $gudangBandung = '019ecf7e-b92b-70b7-b4f1-369585484e57';
        $binInbound    = '019ecf7e-b936-7378-abc6-0aa4a3409da6'; // Bandung INBOUND

        $items = [
            [
                'item_id'    => '019ecf7e-b95c-72c8-9b6f-8685587e46e9', // LAPTOP-001-8GB
                'source_bin' => '019ecf7e-b935-711b-bf37-4406c95bd9c7', // F1-R1-C2-B1
                'qty'        => 2,
            ],
            [
                'item_id'    => '019ecf7e-b963-70d6-bfae-cae22e174414', // MOUSE-001-BLK
                'source_bin' => '019ecf7e-b935-711b-bf37-4406c95bd9c7', // F1-R1-C2-B1
                'qty'        => 5,
            ],
            [
                'item_id'    => '019ecf7e-b965-70e1-85e6-ac346ec29fdd', // KBD-001-RED
                'source_bin' => '019ecf7e-b935-711b-bf37-4406c95bd9c7', // F1-R1-C2-B1
                'qty'        => 3,
            ],
        ];

        // Ensure stock exists
        foreach ($items as $itemData) {
            $inv = Inventory::where('item_id', $itemData['item_id'])
                ->where('location_id', $gudangUtama)
                ->where('bin_id', $itemData['source_bin'])
                ->first();

            if (! $inv) {
                Inventory::create([
                    'item_id'     => $itemData['item_id'],
                    'location_id' => $gudangUtama,
                    'bin_id'      => $itemData['source_bin'],
                    'on_hand'     => 50,
                    'reserved'    => 0,
                    'available'   => 50,
                ]);
                $this->command->info("Created stock for item {$itemData['item_id']}");
            } elseif ($inv->available < $itemData['qty']) {
                $inv->update([
                    'on_hand'   => $inv->on_hand + 50,
                    'available' => $inv->available + 50,
                ]);
                $this->command->info("Topped up stock for item {$itemData['item_id']}");
            }
        }

        // Create draft transfer
        $transferNumber = 'TRF-' . now()->format('Ymd') . '-DRF' . rand(10, 99);

        $transfer = InventoryTransfer::create([
            'transfer_number'       => $transferNumber,
            'source_location_id'    => $gudangUtama,
            'destination_location_id' => $gudangBandung,
            'status'                => InventoryTransfer::STATUS_DRAFT,
            'notes'                 => 'Dummy transfer untuk testing flow baru',
            'created_by'            => 'staff-gudang@cilupbah.id',
        ]);

        foreach ($items as $itemData) {
            $inv = Inventory::where('item_id', $itemData['item_id'])
                ->where('location_id', $gudangUtama)
                ->where('bin_id', $itemData['source_bin'])
                ->first();

            // Reserve stock
            $inv->update([
                'reserved'  => $inv->reserved + $itemData['qty'],
                'available' => $inv->available - $itemData['qty'],
            ]);

            InventoryTransferItem::create([
                'transfer_id'      => $transfer->id,
                'item_id'          => $itemData['item_id'],
                'qty'              => $itemData['qty'],
                'source_bin_id'    => $itemData['source_bin'],
                'destination_bin_id' => $binInbound,
            ]);
        }

        $this->command->info("Draft transfer created: {$transferNumber}");
        $this->command->info("  From: Gudang Utama → Gudang Bandung");
        $this->command->info("  Items: LAPTOP-001 (2), MOUSE-001 (5), KBD-001 (3)");
        $this->command->info("  Status: DRAFT — siap untuk 'Kirim Transfer'");
    }
}
