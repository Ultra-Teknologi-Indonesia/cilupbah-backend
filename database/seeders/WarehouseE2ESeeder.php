<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

class WarehouseE2ESeeder extends Seeder
{
    public function run(): void
    {

        $pusat = Location::where('location_code', Location::SYSTEM_PUSAT_CODE)->firstOrFail();
        $kecil = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->firstOrFail();

        $categoryId = DB::table('categories')->where('name', 'E2E Kategori')->value('id')
            ?: DB::table('categories')->insertGetId([
                'name'       => 'E2E Kategori',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $supplier = DB::table('contacts')->where('code', 'E2E-SUP-01')->first();
        if (! $supplier) {
            $supplierId = (string) Str::uuid7();
            DB::table('contacts')->insert([
                'id'         => $supplierId,
                'code'       => 'E2E-SUP-01',
                'name'       => 'Supplier E2E',
                'type'       => 'SUPPLIER',
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $supplierId = $supplier->id;
        }

        $variants = [];
        foreach ([
            ['sku' => 'E2E-SKU-A', 'name' => 'E2E Barang A'],
            ['sku' => 'E2E-SKU-B', 'name' => 'E2E Barang B'],
            ['sku' => 'E2E-SKU-C', 'name' => 'E2E Barang C'],
            ['sku' => 'E2E-SKU-K', 'name' => 'E2E Barang K'],
        ] as $spec) {
            $product = Product::firstOrCreate(
                ['sku' => $spec['sku']],
                [
                    'name'        => $spec['name'],
                    'category_id' => $categoryId,
                    'order_type'  => 'REGULER',
                    'condition'   => 'NEW',
                ]
            );

            $variant = ProductVariant::firstOrCreate(
                ['sku' => $spec['sku']],
                [
                    'product_id' => $product->id,
                    'barcode'    => 'BC-' . $spec['sku'],
                    'is_active'  => true,
                ]
            );
            $variants[$spec['sku']] = $variant;
        }

        $binsPusat = $this->ensureBins($pusat, ['A-01', 'A-02', 'A-03', 'B-01']);
        $binsKecil = $this->ensureBins($kecil, ['K-01', 'K-02', 'K-03']);

        $pusatSrcBin = $this->findStagingBin($pusat) ?? $binsPusat['A-01']->id;
        $kecilSrcBin = $this->findStagingBin($kecil) ?? $binsKecil['K-01']->id;

        $this->ensureStock($pusat->id, $pusatSrcBin, $variants['E2E-SKU-A']->id, 100);
        $this->ensureStock($pusat->id, $pusatSrcBin, $variants['E2E-SKU-B']->id, 50);
        $this->ensureStock($kecil->id, $kecilSrcBin, $variants['E2E-SKU-K']->id, 30);

        $pusatDefaultBin = $pusatSrcBin;
        $kecilDefaultBin = $kecilSrcBin;

        $poNumber = 'PO-E2E-001';
        if (! PurchaseOrder::where('po_number', $poNumber)->exists()) {
            DB::transaction(function () use ($poNumber, $supplierId, $pusat, $variants) {
                $poId = (string) Str::uuid7();
                PurchaseOrder::create([
                    'id'          => $poId,
                    'po_number'   => $poNumber,
                    'contact_id'  => $supplierId,
                    'location_id' => $pusat->id,
                    'status'      => PurchaseOrder::STATUS_OPEN,
                    'order_date'  => now()->toDateString(),
                    'expected_date' => now()->addDay()->toDateString(),
                    'created_by'  => 'e2e-seeder',
                    'sub_total'   => 500000,
                    'total_amount' => 500000,
                ]);

                foreach ([
                    ['sku' => 'E2E-SKU-A', 'qty' => 20],
                    ['sku' => 'E2E-SKU-C', 'qty' => 15],
                ] as $poi) {
                    DB::table('purchase_order_items')->insert([
                        'id'                => (string) Str::uuid7(),
                        'purchase_order_id' => $poId,
                        'item_id'           => $variants[$poi['sku']]->id,
                        'qty'               => $poi['qty'],
                        'received_qty'      => 0,
                        'unit_price'        => 15000,
                        'amount'            => $poi['qty'] * 15000,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }
            });
            $this->command->line("  [1] Created PO {$poNumber} (WH-PUSAT) — observer auto-buat Inbound DRAFT");
        } else {
            $this->command->line("  [1] PO {$poNumber} sudah ada, skip");
        }

        $trfKecilNumber = 'TRF-E2E-KE-KECIL-001';
        if (! InventoryTransfer::where('transfer_number', $trfKecilNumber)->exists()) {
            $trf = InventoryTransfer::create([
                'transfer_number'        => $trfKecilNumber,
                'source_location_id'     => $pusat->id,
                'destination_location_id' => $kecil->id,
                'status'                 => InventoryTransfer::STATUS_DRAFT,
                'notes'                  => 'E2E test: Transfer Gudang Pusat → Gudang Kecil',
                'created_by'             => 'e2e-seeder',
            ]);
            InventoryTransferItem::create([
                'inventory_transfer_id' => $trf->id,
                'item_id'               => $variants['E2E-SKU-A']->id,
                'qty'                   => 10,
                'source_bin_id'         => $pusatDefaultBin,
            ]);
            $this->command->line("  [2] Created Transfer {$trfKecilNumber} DRAFT (PUSAT→KECIL)");
        } else {
            $this->command->line("  [2] Transfer {$trfKecilNumber} sudah ada, skip");
        }

        $trfPusatNumber = 'TRF-E2E-KE-PUSAT-001';
        if (! InventoryTransfer::where('transfer_number', $trfPusatNumber)->exists()) {
            $trf = InventoryTransfer::create([
                'transfer_number'        => $trfPusatNumber,
                'source_location_id'     => $kecil->id,
                'destination_location_id' => $pusat->id,
                'status'                 => InventoryTransfer::STATUS_DRAFT,
                'notes'                  => 'E2E test: Transfer Gudang Kecil → Gudang Pusat',
                'created_by'             => 'e2e-seeder',
            ]);
            InventoryTransferItem::create([
                'inventory_transfer_id' => $trf->id,
                'item_id'               => $variants['E2E-SKU-K']->id,
                'qty'                   => 5,
                'source_bin_id'         => $kecilDefaultBin,
            ]);
            $this->command->line("  [3] Created Transfer {$trfPusatNumber} DRAFT (KECIL→PUSAT)");
        } else {
            $this->command->line("  [3] Transfer {$trfPusatNumber} sudah ada, skip");
        }

        $this->command->info('WarehouseE2ESeeder completed successfully!');
        $this->command->line('');
        $this->command->line('Data untuk 3 skenario mobile testing:');
        $this->command->line('  [1] PO PO-E2E-001 di WH-PUSAT — Inbound DRAFT auto-generated');
        $this->command->line('  [2] Transfer TRF-E2E-KE-KECIL-001 DRAFT (PUSAT→KECIL)');
        $this->command->line('  [3] Transfer TRF-E2E-KE-PUSAT-001 DRAFT (KECIL→PUSAT)');
        $this->command->line('');
        $this->command->line('SKU tersedia:');
        $this->command->line('  E2E-SKU-A, E2E-SKU-B, E2E-SKU-C, E2E-SKU-K');
        $this->command->line('');
        $this->command->line('Bin tambahan:');
        $this->command->line('  WH-PUSAT: A-01, A-02, A-03, B-01 (1 rak = 1 SKU, SKU boleh di M rak)');
        $this->command->line('  Gudang Kecil: K-01, K-02, K-03 (strict 1 rak 1 SKU + 1 SKU 1 rak)');
    }

    private function findStagingBin(Location $location): ?string
    {
        $bin = LocationBin::where('location_id', $location->id)
            ->where('is_inbound', true)
            ->first();
        return $bin?->id;
    }

    private function ensureBins(Location $location, array $binCodes): array
    {
        $result = [];
        foreach ($binCodes as $code) {
            $finalCode = $location->location_code . '-' . $code;
            $bin = LocationBin::firstOrCreate(
                ['bin_final_code' => $finalCode],
                [
                    'location_id'           => $location->id,
                    'bin_code'              => $code,
                    'is_inbound'            => false,
                    'is_stock_acknowledged' => true,
                ]
            );
            $result[$code] = $bin;
        }
        return $result;
    }

    private function ensureStock(string $locationId, string $binId, string $itemId, int $minQty): void
    {
        $inv = Inventory::firstOrCreate(
            [
                'location_id' => $locationId,
                'bin_id'      => $binId,
                'item_id'     => $itemId,
                'batch_no'    => '',
                'serial_no'   => '',
            ],
            [
                'on_hand'   => $minQty,
                'available' => $minQty,
                'on_order'  => 0,
            ]
        );

        if ($inv->available < $minQty) {
            $delta = $minQty - $inv->available;
            $inv->on_hand   += $delta;
            $inv->available += $delta;
            $inv->save();
        }
    }
}
