<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Inventory\Models\StockOpname;
use Modules\Inventory\Models\StockOpnameItem;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Product\Models\ProductVariant;
use App\Models\User;
use Ramsey\Uuid\Uuid;

class StockOpnameSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Location::where('location_code', 'WH-MAIN')->first();

        if (!$warehouse) {
            $this->command->warn('StockOpnameSeeder: WH-MAIN tidak ditemukan. Jalankan InboundDatabaseSeeder terlebih dahulu.');
            return;
        }

        $bin1 = LocationBin::where('location_id', $warehouse->id)
            ->where('bin_final_code', 'F1-R1-C1-B1')
            ->first();

        $bin2 = LocationBin::where('location_id', $warehouse->id)
            ->where('bin_final_code', 'F1-R1-C2-B1')
            ->first();

        if (!$bin1 || !$bin2) {
            $this->command->warn('StockOpnameSeeder: Location bins tidak lengkap.');
            return;
        }

        $laptop = ProductVariant::where('sku', 'LAPTOP-001-8GB')->first();
        $mouse = ProductVariant::where('sku', 'MOUSE-001-BLK')->first();
        $keyboard = ProductVariant::where('sku', 'KBD-001-RED')->first();

        if (!$laptop || !$mouse || !$keyboard) {
            $this->command->warn('StockOpnameSeeder: Product variants tidak lengkap.');
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

        $statusMap = [
            1  => StockOpname::STATUS_DRAFT,
            2  => StockOpname::STATUS_DRAFT,
            3  => StockOpname::STATUS_IN_PROGRESS,
            4  => StockOpname::STATUS_IN_PROGRESS,
            5  => StockOpname::STATUS_FINALIZED,
            6  => StockOpname::STATUS_FINALIZED,
            7  => StockOpname::STATUS_CANCELLED,
            8  => StockOpname::STATUS_DRAFT,
            9  => StockOpname::STATUS_IN_PROGRESS,
            10 => StockOpname::STATUS_FINALIZED,
            11 => StockOpname::STATUS_DRAFT,
            12 => StockOpname::STATUS_IN_PROGRESS,
            13 => StockOpname::STATUS_FINALIZED,
            14 => StockOpname::STATUS_CANCELLED,
            15 => StockOpname::STATUS_DRAFT,
            16 => StockOpname::STATUS_IN_PROGRESS,
            17 => StockOpname::STATUS_FINALIZED,
            18 => StockOpname::STATUS_DRAFT,
            19 => StockOpname::STATUS_IN_PROGRESS,
            20 => StockOpname::STATUS_CANCELLED,
        ];

        $notes = [
            'Opname bulanan zona A',
            'Stock check lantai 1',
            'Pengecekan stok promo',
            'Opname rutin rak elektronik',
            'Audit stok akhir bulan',
            'Cek selisih stok keyboard',
            'Opname dadakan zona B',
            'Stock check barang baru masuk',
            'Verifikasi stok retur',
            'Pengecekan stok fast-moving',
            'Opname periodik lantai 2',
            'Stock take zona penyimpanan utama',
            'Cek stok sebelum promo',
            'Opname triwulanan gudang utama',
            'Pengecekan stok slow-moving',
            'Audit internal zona C',
            'Opname pasca transfer masuk',
            'Stock check area inbound',
            'Verifikasi selisih sistem',
            'Opname akhir periode',
        ];

        $batchNos = ['BATCH-2024-001', 'BATCH-2024-002', 'BATCH-2024-003', ''];
        $serialNos = ['SN-LP-001', 'SN-LP-002', 'SN-MS-001', 'SN-KB-001', ''];
        $differenceReasons = [
            'Barang rusak',
            'Salah hitung sebelumnya',
            'Hilang',
            'Expired',
            'Salah penempatan bin',
            'Retur belum diproses',
        ];

        $count = 0;

        for ($i = 1; $i <= 20; $i++) {
            $seq = str_pad($i, 4, '0', STR_PAD_LEFT);
            $dayOffset = (int) (($i - 1) * 14 / 20); 
            $createdAt = now()->subDays($dayOffset)->subMinutes($i * 11);

            $status = $statusMap[$i];
            $note = $notes[$i - 1];

            $opnameData = [
                'location_id' => $warehouse->id,
                'status' => $status,
                'notes' => $note,
                'created_by' => $staff->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if (in_array($status, [StockOpname::STATUS_IN_PROGRESS, StockOpname::STATUS_FINALIZED])) {
                $opnameData['process_by'] = $staff->name;
            }

            if ($status === StockOpname::STATUS_FINALIZED) {
                $opnameData['finalized_by'] = $staff->name;
                $opnameData['finalized_at'] = $createdAt->copy()->addHours(3);
            }

            $opname = StockOpname::firstOrCreate(
                ['opname_no' => "OP-SEED-{$seq}"],
                $opnameData
            );

            $itemCount = ($i % 6) + 3; 

            $itemRows = [];
            $now = now();

            for ($j = 0; $j < $itemCount; $j++) {
                $variant = $variants[($i + $j) % count($variants)];
                $bin = $bins[($i + $j) % count($bins)];
                $qtySystem = (($i + $j) % 10 + 1) * 5; 
                $batchNo = $batchNos[($i + $j) % count($batchNos)];
                $serialNo = $variant->sku === 'LAPTOP-001-8GB'
                    ? $serialNos[($i + $j) % count($serialNos)]
                    : '';
                $expiredDate = ($i + $j) % 4 === 0
                    ? now()->addMonths(6 + ($j * 2))->toDateString()
                    : null;

                $qtyActual = null;
                $qtyDifference = null;
                $reason = null;
                $countedBy = null;
                $countedAt = null;

                if ($status === StockOpname::STATUS_FINALIZED) {

                    $diff = [0, 0, 0, -1, 1, -2, 0, 2, -3, 0];
                    $qtyDiff = $diff[($i + $j) % count($diff)];
                    $qtyActual = $qtySystem + $qtyDiff;
                    $qtyDifference = $qtyDiff;
                    $countedBy = $staff->name;
                    $countedAt = $createdAt->copy()->addHours(2)->addMinutes($j * 10);
                    if ($qtyDiff !== 0) {
                        $reason = $differenceReasons[abs($i + $j) % count($differenceReasons)];
                    }
                } elseif ($status === StockOpname::STATUS_IN_PROGRESS) {

                    if ($j < (int) ($itemCount * 0.6)) {

                        $diff = [0, -1, 0, 1, -2, 0];
                        $qtyDiff = $diff[($i + $j) % count($diff)];
                        $qtyActual = $qtySystem + $qtyDiff;
                        $qtyDifference = $qtyDiff;
                        $countedBy = $staff->name;
                        $countedAt = $createdAt->copy()->addHours(1)->addMinutes($j * 15);
                        if ($qtyDiff !== 0) {
                            $reason = $differenceReasons[abs($i + $j) % count($differenceReasons)];
                        }
                    }

                } elseif ($status === StockOpname::STATUS_CANCELLED) {

                    if ($j < 2 && $i % 2 === 0) {
                        $qtyActual = $qtySystem;
                        $qtyDifference = 0;
                        $countedBy = $staff->name;
                        $countedAt = $createdAt->copy()->addMinutes(45);
                    }
                }

                $itemRows[] = [
                    'id' => Uuid::uuid7()->toString(),
                    'stock_opname_id' => $opname->id,
                    'item_id' => $variant->id,
                    'bin_id' => $bin->id,
                    'batch_no' => $batchNo,
                    'serial_no' => $serialNo,
                    'expired_date' => $expiredDate,
                    'qty_system' => $qtySystem,
                    'qty_actual' => $qtyActual,
                    'qty_difference' => $qtyDifference,
                    'reason' => $reason,
                    'counted_by' => $countedBy,
                    'counted_at' => $countedAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($opname->wasRecentlyCreated) {
                StockOpnameItem::insert($itemRows);
            }

            $count++;
        }

        $this->command->info("StockOpnameSeeder: {$count} stock opname documents created with mixed statuses + items.");
    }
}
