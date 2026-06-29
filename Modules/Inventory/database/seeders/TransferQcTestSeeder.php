<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;

class TransferQcTestSeeder extends Seeder
{
    public function run(): void
    {
        $gudangUtama   = '019ecf7e-b91b-70d4-87b9-0b18f4e5e9f0';
        $gudangBandung = '019ecf7e-b92b-70b7-b4f1-369585484e57';
        $binSource     = '019ecf7e-b935-711b-bf37-4406c95bd9c7'; 
        $binInbound    = '019ecf7e-b936-7378-abc6-0aa4a3409da6'; 

        $items = [
            [
                'item_id' => '019ecf7e-b95c-72c8-9b6f-8685587e46e9', 
                'qty'     => 5,
            ],
            [
                'item_id' => '019ecf7e-b963-70d6-bfae-cae22e174414', 
                'qty'     => 10,
            ],
            [
                'item_id' => '019ecf7e-b965-70e1-85e6-ac346ec29fdd', 
                'qty'     => 8,
            ],
        ];

        foreach ($items as $itemData) {
            $inv = Inventory::where('item_id', $itemData['item_id'])
                ->where('location_id', $gudangUtama)
                ->where('bin_id', $binSource)
                ->first();

            if (! $inv) {
                Inventory::create([
                    'item_id'     => $itemData['item_id'],
                    'location_id' => $gudangUtama,
                    'bin_id'      => $binSource,
                    'on_hand'     => 100,
                    'reserved'    => 0,
                    'available'   => 100,
                ]);
            } elseif ($inv->available < $itemData['qty']) {
                $inv->update([
                    'on_hand'   => $inv->on_hand + 100,
                    'available' => $inv->available + 100,
                ]);
            }
        }

        $transferNumber = 'TRF-' . now()->format('Ymd') . '-QC' . rand(10, 99);

        $transfer = InventoryTransfer::create([
            'transfer_number'         => $transferNumber,
            'source_location_id'      => $gudangUtama,
            'destination_location_id' => $gudangBandung,
            'status'                  => InventoryTransfer::STATUS_IN_TRANSIT,
            'notes'                   => 'Transfer untuk test QC — ada barang rusak',
            'created_by'              => 'staff-gudang@cilupbah.id',
            'shipped_at'              => now(),
        ]);

        foreach ($items as $itemData) {
            $inv = Inventory::where('item_id', $itemData['item_id'])
                ->where('location_id', $gudangUtama)
                ->where('bin_id', $binSource)
                ->first();

            $inv->update([
                'on_hand'   => $inv->on_hand - $itemData['qty'],
                'reserved'  => $inv->reserved,
                'available' => $inv->available - $itemData['qty'],
            ]);

            InventoryTransferItem::create([
                'inventory_transfer_id'        => $transfer->id,
                'item_id'            => $itemData['item_id'],
                'qty'                => $itemData['qty'],
                'source_bin_id'      => $binSource,
                'destination_bin_id' => $binInbound,
            ]);
        }

        $this->command->info('');
        $this->command->info("Transfer IN_TRANSIT created: {$transferNumber}");
        $this->command->info('  Gudang Utama → Gudang Bandung');
        $this->command->info('  LAPTOP-001 (5), MOUSE-001 (10), KBD-001 (8)');
        $this->command->info('  Status: IN_TRANSIT — siap untuk Terima Barang + QC');
    }
}
