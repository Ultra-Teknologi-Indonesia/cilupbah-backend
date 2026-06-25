<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Inbound\Models\Inbound;
use Modules\Inbound\Models\InboundItem;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;

/**
 * Builds a realistic, business-logic-accurate inventory history spread across the
 * last 12 months for every stockable (non-bundle) product variant:
 *
 *   - Beginning balance        (stock_adjustments, is_beginning_balance)
 *   - Purchasing               (purchase_orders + items)
 *   - Goods-in / barang masuk  (inbounds + items, posts stock IN)
 *   - Goods-out / barang keluar(stock_adjustments OUT + inter-warehouse transfers)
 *   - Open / partial POs        (on_order at "now")
 *
 * Every stock effect is posted to inventories + inventory_movements with a correct
 * running balance and avg_cost, mirroring InventoryService::adjust() — but with
 * explicit source tags and back-dated transaction dates. No sales orders are created.
 */
class InventoryHistorySeeder extends Seeder
{
    private const CREATED_BY = 'seeder@cilupbah.id';

    private string $mainLocId;
    private ?string $mainBinId;
    private string $branchLocId;
    private ?string $branchBinId;

    /** @var array<int,string> contact ids */
    private array $suppliers = [];

    /** local mirror of on_hand per "variantId@locId" to keep outbound non-negative */
    private array $onHand = [];

    private int $poCounter = 0;
    private int $adjCounter = 0;
    private int $inCounter = 0;
    private int $trfCounter = 0;

    public function run(): void
    {
        // Not idempotent — guard against duplicating an existing history.
        if (InventoryMovement::where('created_by', self::CREATED_BY)->exists()) {
            $this->command->warn('InventoryHistorySeeder: seeded history already exists — skipping. '
                . 'Purge rows where created_by=' . self::CREATED_BY . ' to reseed.');
            return;
        }

        $this->command->info('InventoryHistorySeeder: preparing warehouses & suppliers...');
        $this->prepareLocations();
        $this->prepareSuppliers();

        $products = Product::query()
            ->where('is_bundle', false)
            ->where('is_purchased', true)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)])
            ->get();

        if ($products->isEmpty()) {
            $this->command->warn('  No stockable products found. Run ProductCatalogSeeder first.');
            return;
        }

        foreach ($products as $product) {
            $this->buildProductHistory($product);
        }

        $this->command->info('InventoryHistorySeeder: done.');
        $this->command->table(['Table', 'Count'], [
            ['purchase_orders', PurchaseOrder::count()],
            ['inbounds', Inbound::count()],
            ['stock_adjustments', StockAdjustment::count()],
            ['inventory_transfers', InventoryTransfer::count()],
            ['inventories', Inventory::count()],
            ['inventory_movements', InventoryMovement::count()],
        ]);
    }

    // ---------------------------------------------------------------------
    // Setup
    // ---------------------------------------------------------------------

    private function prepareLocations(): void
    {
        $main = Location::where('location_code', 'WH-PUSAT')->first()
            ?? Location::where('is_warehouse', true)->where('is_system', true)->first();

        if (! $main) {
            throw new \RuntimeException('InventoryHistorySeeder: main warehouse (WH-PUSAT) not found.');
        }
        $this->mainLocId = $main->id;
        $this->mainBinId = LocationBin::where('location_id', $main->id)->value('id');

        $branch = Location::firstOrCreate(
            ['location_code' => 'WH-BANDUNG'],
            [
                'location_name' => 'Gudang Bandung',
                'location_type' => 'WAREHOUSE',
                'is_warehouse' => true,
                'is_active' => true,
                'is_system' => false,
            ]
        );
        $this->branchLocId = $branch->id;
        $this->branchBinId = LocationBin::firstOrCreate(
            ['location_id' => $branch->id, 'bin_final_code' => 'DEFAULT'],
            [
                'floor_code' => 'F1',
                'row_code' => 'R1',
                'column_code' => 'C1',
                'bin_code' => 'B1',
                'is_inbound' => true,
            ]
        )->id;
    }

    private function prepareSuppliers(): void
    {
        $defs = [
            ['SUP-ACC-01', 'CV Aksesoris Jaya', 'Aksesoris Jaya Group'],
            ['SUP-ACC-02', 'PT Gadget Sentosa', 'Gadget Sentosa'],
            ['SUP-ACC-03', 'Toko Grosir Cibaduyut', 'Grosir Cibaduyut'],
            ['SUP-ACC-04', 'PT Mitra Digital Nusantara', 'Mitra Digital'],
            ['SUP-ACC-05', 'UD Sumber Casing', 'Sumber Casing'],
        ];

        foreach ($defs as [$code, $name, $company]) {
            $existing = DB::table('suppliers')->where('code', $code)->value('id');
            if ($existing) {
                $this->suppliers[] = $existing;
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('suppliers')->insert([
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'company_name' => $company,
                'status' => 'active',
                'payment_term' => '30 hari',
                'city' => 'Bandung',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->suppliers[] = $id;
        }
    }

    // ---------------------------------------------------------------------
    // Per-product timeline
    // ---------------------------------------------------------------------

    private function buildProductHistory(Product $product): void
    {
        $variants = $product->variants;
        if ($variants->isEmpty()) {
            return;
        }

        // 1) Beginning balance ~ 11 months ago. A single strictly-increasing date
        //    cursor walks the whole timeline so that, per (item, location), every
        //    posting is chronological and the running balance stays monotonic.
        $cursor = now()->subMonths(11)->subDays(random_int(0, 15))->setTime(9, 0);
        $this->beginningBalance($product, $variants, $cursor->copy());

        $end = now()->subDays(7);
        while ($cursor->lt($end)) {
            $cursor = $cursor->copy()
                ->addDays(random_int(8, 26))
                ->setTime(random_int(9, 17), random_int(0, 59), random_int(0, 59));
            if ($cursor->gte($end)) {
                break;
            }

            $roll = random_int(1, 100);
            if ($roll <= 65) {
                // pembelian → barang masuk
                $this->purchaseAndReceive($product, $variants, $cursor->copy());
            } elseif ($roll <= 90) {
                // barang keluar (rusak/susut/sampel)
                $this->adjustmentOut($variants, $cursor->copy());
            } else {
                // distribusi antar gudang
                $this->transfer($variants, $cursor->copy());
            }
        }

        // 2) Open / partial PO within the last few days (creates on_order at "now")
        if (random_int(1, 10) <= 4) {
            $this->openPurchaseOrder($product, $variants);
        }
    }

    private function beginningBalance(Product $product, $variants, Carbon $date): void
    {
        $this->adjCounter++;
        $adj = StockAdjustment::create([
            'adjustment_no' => sprintf('ADJ-BEG-%04d', $this->adjCounter),
            'transaction_date' => $date,
            'location_id' => $this->mainLocId,
            'status' => StockAdjustment::STATUS_APPROVED,
            'is_beginning_balance' => true,
            'notes' => 'Saldo awal persediaan',
            'created_by' => self::CREATED_BY,
            'approved_by' => self::CREATED_BY,
            'approved_at' => $date,
        ]);
        $this->touchDates($adj, $date);

        foreach ($variants as $v) {
            $qty = $this->beginningQty($v->buy_price);
            StockAdjustmentItem::create([
                'stock_adjustment_id' => $adj->id,
                'item_id' => $v->id,
                'bin_id' => $this->mainBinId,
                'batch_no' => '',
                'serial_no' => '',
                'system_qty' => 0,
                'actual_qty' => $qty,
                'difference_qty' => $qty,
                'notes' => 'Saldo awal',
            ]);
            $this->post($v->id, $this->mainLocId, $this->mainBinId, $qty, 'BEGINNING', $date, $adj->adjustment_no, (float) $v->buy_price);
        }
    }

    private function purchaseAndReceive(Product $product, $variants, Carbon $date): void
    {
        $orderDate = (clone $date)->subDays(random_int(2, 6));
        $supplierId = $this->suppliers[array_rand($this->suppliers)];

        $this->poCounter++;
        $poNumber = sprintf('PO-%s-%04d', $date->format('ym'), $this->poCounter);

        $items = [];
        $subTotal = 0;
        foreach ($variants as $v) {
            $qty = $this->restockQty($v->buy_price);
            $unit = (int) $v->buy_price;
            $amount = $qty * $unit;
            $subTotal += $amount;
            $items[] = ['variant' => $v, 'qty' => $qty, 'unit' => $unit, 'amount' => $amount];
        }

        $poId = $this->insertPurchaseOrder(
            $poNumber,
            $supplierId,
            PurchaseOrder::STATUS_FULLY_RECEIVED,
            $orderDate,
            $date,
            $subTotal,
            'Pembelian restok rutin'
        );

        $this->inCounter++;
        $inbound = Inbound::create([
            'location_id' => $this->mainLocId,
            'transaction_number' => sprintf('IN-%s-%04d', $date->format('ym'), $this->inCounter),
            'reference_number' => $poNumber,
            'type' => Inbound::TYPE_PURCHASE_ORDER,
            'source_type' => 'PURCHASE_ORDER',
            'source_id' => $poId,
            'status' => Inbound::STATUS_COMPLETED,
            'expected_date' => $date,
            'created_by' => self::CREATED_BY,
        ]);
        $this->touchDates($inbound, $date);

        foreach ($items as $it) {
            $v = $it['variant'];
            $this->insertPoItem($poId, $v->id, $it['qty'], $it['qty'], $it['unit'], $it['amount'], $date);

            $ii = InboundItem::create([
                'inbound_id' => $inbound->id,
                'item_id' => $v->id,
                'expected_qty' => $it['qty'],
                'received_qty' => $it['qty'],
                'putaway_qty' => $it['qty'],
                'condition' => 'GOOD',
            ]);
            $this->touchDates($ii, $date);

            $this->post($v->id, $this->mainLocId, $this->mainBinId, $it['qty'], 'PURCHASE', $date, $poNumber, (float) $it['unit']);
        }
    }

    private function adjustmentOut($variants, Carbon $date): void
    {
        // Pick a subset of variants that actually have stock
        $candidates = $variants->filter(fn ($v) => $this->stock($v->id, $this->mainLocId) > 5);
        if ($candidates->isEmpty()) {
            return;
        }

        $this->adjCounter++;
        $reasons = ['Barang rusak/cacat', 'Selisih stok opname', 'Sampel marketing', 'Hilang/susut'];
        $adj = StockAdjustment::create([
            'adjustment_no' => sprintf('ADJ-OUT-%04d', $this->adjCounter),
            'transaction_date' => $date,
            'location_id' => $this->mainLocId,
            'status' => StockAdjustment::STATUS_APPROVED,
            'is_beginning_balance' => false,
            'notes' => $reasons[array_rand($reasons)],
            'created_by' => self::CREATED_BY,
            'approved_by' => self::CREATED_BY,
            'approved_at' => $date,
        ]);
        $this->touchDates($adj, $date);

        foreach ($candidates as $v) {
            $current = $this->stock($v->id, $this->mainLocId);
            $out = min($current, random_int(1, max(1, (int) floor($current * 0.15))));
            if ($out <= 0) {
                continue;
            }
            StockAdjustmentItem::create([
                'stock_adjustment_id' => $adj->id,
                'item_id' => $v->id,
                'bin_id' => $this->mainBinId,
                'batch_no' => '',
                'serial_no' => '',
                'system_qty' => $current,
                'actual_qty' => $current - $out,
                'difference_qty' => -$out,
                'notes' => $adj->notes,
            ]);
            $this->post($v->id, $this->mainLocId, $this->mainBinId, -$out, 'ADJUSTMENT', $date, $adj->adjustment_no);
        }
    }

    private function transfer($variants, Carbon $date): void
    {
        $candidates = $variants->filter(fn ($v) => $this->stock($v->id, $this->mainLocId) > 10);
        if ($candidates->isEmpty()) {
            return;
        }
        $candidates = $candidates->take(3);

        $this->trfCounter++;
        $shipDate = (clone $date);
        $recvDate = (clone $date)->addDays(random_int(1, 3));

        $trf = InventoryTransfer::create([
            'transfer_number' => sprintf('TRFO-%s-%04d', $date->format('ym'), $this->trfCounter),
            'receive_number' => sprintf('TRFI-%s-%04d', $date->format('ym'), $this->trfCounter),
            'source_location_id' => $this->mainLocId,
            'destination_location_id' => $this->branchLocId,
            'status' => InventoryTransfer::STATUS_RECEIVED,
            'notes' => 'Distribusi ke cabang Bandung',
            'created_by' => self::CREATED_BY,
            'received_by' => self::CREATED_BY,
            'shipped_at' => $shipDate,
            'received_at' => $recvDate,
        ]);
        $this->touchDates($trf, $date);

        foreach ($candidates as $v) {
            $current = $this->stock($v->id, $this->mainLocId);
            $qty = min($current, random_int(2, max(2, (int) floor($current * 0.25))));
            if ($qty <= 0) {
                continue;
            }
            InventoryTransferItem::create([
                'inventory_transfer_id' => $trf->id,
                'item_id' => $v->id,
                'qty' => $qty,
                'received_qty' => $qty,
                'rejected_qty' => 0,
                'condition' => 'GOOD',
                'source_bin_id' => $this->mainBinId,
                'destination_bin_id' => $this->branchBinId,
                'batch_no' => '',
                'serial_no' => '',
            ]);
            $this->post($v->id, $this->mainLocId, $this->mainBinId, -$qty, 'TRANSFER_OUT', $shipDate, $trf->transfer_number);
            $this->post($v->id, $this->branchLocId, $this->branchBinId, $qty, 'TRANSFER_IN', $recvDate, $trf->receive_number, (float) $v->buy_price);
        }
    }

    private function openPurchaseOrder(Product $product, $variants): void
    {
        $orderDate = now()->subDays(random_int(0, 5));
        $supplierId = $this->suppliers[array_rand($this->suppliers)];
        $partial = random_int(0, 1) === 1;

        $this->poCounter++;
        $poNumber = sprintf('PO-%s-%04d', now()->format('ym'), $this->poCounter);

        $subTotal = 0;
        $lines = [];
        foreach ($variants as $v) {
            $qty = $this->restockQty($v->buy_price);
            $recv = $partial ? (int) floor($qty / 2) : 0;
            $unit = (int) $v->buy_price;
            $amount = $qty * $unit;
            $subTotal += $amount;
            $lines[] = ['variant' => $v, 'qty' => $qty, 'recv' => $recv, 'unit' => $unit, 'amount' => $amount];
        }

        $poId = $this->insertPurchaseOrder(
            $poNumber,
            $supplierId,
            $partial ? PurchaseOrder::STATUS_PARTIAL_RECEIVED : PurchaseOrder::STATUS_OPEN,
            $orderDate,
            now()->addDays(random_int(3, 14)),
            $subTotal,
            $partial ? 'PO sebagian diterima' : 'PO menunggu kedatangan'
        );

        foreach ($lines as $ln) {
            $v = $ln['variant'];
            $this->insertPoItem($poId, $v->id, $ln['qty'], $ln['recv'], $ln['unit'], $ln['amount'], $orderDate);

            $outstanding = $ln['qty'] - $ln['recv'];
            if ($outstanding > 0) {
                $this->addOnOrder($v->id, $this->mainLocId, $this->mainBinId, $outstanding);
            }
            if ($ln['recv'] > 0) {
                $this->post($v->id, $this->mainLocId, $this->mainBinId, $ln['recv'], 'PURCHASE', $orderDate, $poNumber, (float) $ln['unit']);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Posting primitive (mirrors InventoryService::adjust balance logic)
    // ---------------------------------------------------------------------

    private function post(string $variantId, string $locId, ?string $binId, int $qty, string $source, Carbon $date, ?string $txn = null, ?float $unitCost = null): void
    {
        $inv = Inventory::firstOrCreate(
            ['item_id' => $variantId, 'location_id' => $locId, 'bin_id' => $binId, 'batch_no' => '', 'serial_no' => ''],
            ['on_hand' => 0, 'on_order' => 0, 'reserved' => 0, 'available' => 0, 'avg_cost' => 0]
        );
        if ($inv->wasRecentlyCreated) {
            $this->touchDates($inv, $date);
        }

        $newOnHand = $inv->on_hand + $qty;
        if ($newOnHand < 0) {
            $qty = -$inv->on_hand;
            $newOnHand = 0;
        }

        // weighted average cost on inbound
        if ($qty > 0 && $unitCost !== null) {
            $prevValue = $inv->on_hand * (float) $inv->avg_cost;
            $inv->avg_cost = $newOnHand > 0 ? round(($prevValue + $qty * $unitCost) / $newOnHand, 2) : $unitCost;
        }

        $inv->on_hand = $newOnHand;
        $inv->available = $newOnHand - $inv->reserved;
        $inv->save();

        $key = $variantId . '@' . $locId;
        $this->onHand[$key] = $newOnHand;

        if ($qty === 0) {
            return;
        }

        $mv = InventoryMovement::create([
            'item_id' => $variantId,
            'location_id' => $locId,
            'bin_id' => $binId,
            'transaction_number' => $txn ?? ('MOV-' . Str::upper(Str::random(8))),
            'source' => $source,
            'qty' => $qty,
            'balance' => $newOnHand,
            'transaction_date' => $date,
            'created_by' => self::CREATED_BY,
        ]);
        $this->touchDates($mv, $date);
    }

    private function addOnOrder(string $variantId, string $locId, ?string $binId, int $qty): void
    {
        $inv = Inventory::firstOrCreate(
            ['item_id' => $variantId, 'location_id' => $locId, 'bin_id' => $binId, 'batch_no' => '', 'serial_no' => ''],
            ['on_hand' => 0, 'on_order' => 0, 'reserved' => 0, 'available' => 0, 'avg_cost' => 0]
        );
        $inv->on_order = $inv->on_order + $qty;
        $inv->save();
    }

    private function stock(string $variantId, string $locId): int
    {
        return $this->onHand[$variantId . '@' . $locId] ?? 0;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function beginningQty(float $buyPrice): int
    {
        return $buyPrice >= 60000 ? random_int(10, 30)
            : ($buyPrice >= 20000 ? random_int(30, 80) : random_int(60, 150));
    }

    private function restockQty(float $buyPrice): int
    {
        return $buyPrice >= 60000 ? random_int(6, 20)
            : ($buyPrice >= 20000 ? random_int(20, 60) : random_int(40, 120));
    }

    private function touchDates(Model $model, Carbon $date): void
    {
        $model->created_at = $date;
        $model->updated_at = $date;
        $model->saveQuietly();
    }

    /** Raw insert against the live purchase_orders schema (supplier_id + subtotal/total_amount). */
    private function insertPurchaseOrder(string $poNumber, string $supplierId, string $status, Carbon $orderDate, Carbon $expected, int $total, string $notes): string
    {
        $id = (string) Str::uuid();
        DB::table('purchase_orders')->insert([
            'id' => $id,
            'po_number' => $poNumber,
            'supplier_id' => $supplierId,
            'location_id' => $this->mainLocId,
            'status' => $status,
            'order_date' => $orderDate->toDateString(),
            'expected_date' => $expected->toDateString(),
            'total_amount' => $total,
            'payment_term' => '30 hari',
            'notes' => $notes,
            'created_by' => self::CREATED_BY,
            'created_at' => $orderDate,
            'updated_at' => $orderDate,
        ]);

        return $id;
    }

    private function insertPoItem(string $poId, string $variantId, int $qty, int $receivedQty, int $unitPrice, int $subtotal, Carbon $date): void
    {
        DB::table('purchase_order_items')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $poId,
            'item_id' => $variantId,
            'qty' => $qty,
            'received_qty' => $receivedQty,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}
