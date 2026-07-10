<?php

namespace Modules\Inbound\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundAssignment;
use Modules\Inbound\Models\InboundItem;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\Location;

class InboundReceivingTestSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Location::where('location_code', 'WH-MAIN')->first();

        if (! $warehouse) {
            $this->command->warn('InboundReceivingTestSeeder: WH-MAIN tidak ditemukan. Jalankan InboundDatabaseSeeder terlebih dahulu.');
            return;
        }

        $staff = User::firstOrCreate(
            ['email' => 'staff-gudang@cilupbah.id'],
            [
                'name'     => 'Staff Gudang',
                'password' => Hash::make('password'),
            ]
        );

        if (! $staff->hasRole('warehouse_staff')) {
            try { $staff->assignRole('warehouse_staff'); } catch (\Exception $e) {}
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@cilupbah.id'],
            [
                'name'     => 'Admin Cilupbah',
                'password' => Hash::make('password'),
            ]
        );

        $laptop   = ProductVariant::where('sku', 'LAPTOP-001-8GB')->first();
        $mouse    = ProductVariant::where('sku', 'MOUSE-001-BLK')->first();
        $keyboard = ProductVariant::where('sku', 'KBD-001-RED')->first();

        if (! $laptop || ! $mouse || ! $keyboard) {
            $this->command->warn('InboundReceivingTestSeeder: Product variants tidak lengkap.');
            return;
        }

        $variants = [$laptop, $mouse, $keyboard];
        $count    = 0;

        for ($i = 1; $i <= 5; $i++) {
            $inbound = $this->createInbound($warehouse, $i, Inbound::STATUS_DRAFT, $variants);
            $this->createAssignment($inbound, $staff, $admin, InboundAssignment::STATUS_PENDING);
            $count++;
        }

        for ($i = 6; $i <= 10; $i++) {
            $inbound = $this->createInbound($warehouse, $i, Inbound::STATUS_PARTIAL, $variants, partialReceive: true);
            $this->createAssignment($inbound, $staff, $admin, InboundAssignment::STATUS_IN_PROGRESS);
            $count++;
        }

        for ($i = 11; $i <= 15; $i++) {
            $inbound = $this->createInbound($warehouse, $i, Inbound::STATUS_RECEIVED, $variants, fullyReceived: true);
            $this->createAssignment($inbound, $staff, $admin, InboundAssignment::STATUS_COMPLETED);
            $count++;
        }

        $this->command->info("InboundReceivingTestSeeder: {$count} inbound documents created with assignments.");
        $this->command->info('  Login: staff-gudang@cilupbah.id / password');
    }

    private function createInbound(
        Location $warehouse,
        int $seq,
        string $status,
        array $variants,
        bool $partialReceive = false,
        bool $fullyReceived = false,
    ): Inbound {
        $seqPad    = str_pad($seq, 3, '0', STR_PAD_LEFT);
        $dayOffset = (int) (($seq - 1) / 3);
        $createdAt = now()->subDays($dayOffset)->subMinutes($seq * 11);

        $types = [
            Inbound::TYPE_PURCHASE_ORDER,
            Inbound::TYPE_TRANSIT_IN,
            Inbound::TYPE_CONSIGNMENT,
        ];

        $inbound = Inbound::firstOrCreate(
            ['transaction_number' => "INB-RCV-{$seqPad}"],
            [
                'location_id'      => $warehouse->id,
                'reference_number' => "REF-RCV-{$seqPad}",
                'type'             => $types[($seq - 1) % count($types)],
                'source_type'      => 'seeder',
                'status'           => $status,
                'expected_date'    => $createdAt->copy()->addDays(3),
                'created_by'       => $admin->id,
                'notes'            => "Test penerimaan #{$seq}",
                'created_at'       => $createdAt,
                'updated_at'       => $createdAt,
            ]
        );

        $itemCount = ($seq % 3) + 1;
        for ($j = 0; $j < $itemCount; $j++) {
            $variant     = $variants[($seq + $j) % count($variants)];
            $expectedQty = (($seq + $j) % 5 + 1) * 10;

            $receivedQty = 0;
            $rejectedQty = 0;
            if ($partialReceive) {
                $receivedQty = (int) ($expectedQty * 0.4);
            } elseif ($fullyReceived) {
                $receivedQty = $expectedQty;
            }

            InboundItem::firstOrCreate(
                ['inbound_id' => $inbound->id, 'item_id' => $variant->id],
                [
                    'expected_qty'    => $expectedQty,
                    'received_qty'    => $receivedQty,
                    'rejected_qty'    => $rejectedQty,
                    'putaway_qty'     => 0,
                    'discrepancy_qty' => 0,
                    'condition'       => 'GOOD',
                ]
            );
        }

        return $inbound;
    }

    private function createAssignment(
        Inbound $inbound,
        User $worker,
        User $assigner,
        string $status,
    ): InboundAssignment {
        $data = [
            'assigned_to' => $worker->id,
            'assigned_by' => $assigner->id,
            'status'      => $status,
            'notes'       => 'Assigned by seeder',
        ];

        if ($status === InboundAssignment::STATUS_IN_PROGRESS) {
            $data['started_at'] = now()->subHours(1);
        } elseif ($status === InboundAssignment::STATUS_COMPLETED) {
            $data['started_at']   = now()->subHours(3);
            $data['completed_at'] = now()->subHours(1);
        }

        return InboundAssignment::firstOrCreate(
            ['inbound_id' => $inbound->id, 'assigned_to' => $worker->id],
            $data
        );
    }
}
