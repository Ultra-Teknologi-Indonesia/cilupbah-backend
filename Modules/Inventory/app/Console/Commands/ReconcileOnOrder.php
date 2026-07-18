<?php

namespace Modules\Inventory\Console\Commands;

use App\Traits\StockLockable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Repositories\InventoryMovementRepository;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Product\Repositories\ProductRepository;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;

class ReconcileOnOrder extends Command
{
    use StockLockable;

    protected $signature = 'inventory:reconcile-on-order
        {--fix : Terapkan koreksi. Tanpa flag ini command hanya melapor.}';

    protected $description = 'Bandingkan on_order terhadap reservasi sah (pesanan reserved + transfer draft) dan laporkan/koreksi selisihnya.';

    public function handle(
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
        ProductRepository $productRepository,
    ): int {
        $fix = (bool) $this->option('fix');

        $expected = $this->buildExpected($productRepository);
        $actual = $this->buildActual();

        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        sort($keys);

        $drifts = [];
        foreach ($keys as $key) {
            $exp = $expected[$key] ?? 0;
            $act = $actual[$key] ?? 0;
            if ($exp !== $act) {
                $drifts[$key] = ['expected' => $exp, 'actual' => $act, 'drift' => $act - $exp];
            }
        }

        if (empty($drifts)) {
            $this->info('on_order sudah konsisten dengan reservasi sah. Tidak ada selisih.');
            return self::SUCCESS;
        }

        $rows = [];
        $total = 0;
        foreach ($drifts as $key => $d) {
            [$itemId, $locationId] = explode('|', $key);
            $rows[] = [$itemId, $locationId, $d['expected'], $d['actual'], $d['drift']];
            $total += $d['drift'];
        }

        $this->table(['Item', 'Lokasi', 'Expected', 'Aktual', 'Drift'], $rows);
        $this->warn(sprintf('%d baris selisih, total drift %+d unit.', count($drifts), $total));

        if (! $fix) {
            $this->line('Jalankan ulang dengan --fix untuk menerapkan koreksi.');
            return self::SUCCESS;
        }

        $applied = 0;
        $residual = 0;

        foreach ($drifts as $key => $d) {
            [$itemId, $locationId] = explode('|', $key);
            $delta = $d['drift'];

            $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $delta, $d, $inventoryRepository, $movementRepository, &$applied, &$residual) {
                DB::transaction(function () use ($itemId, $locationId, $delta, $d, $inventoryRepository, $movementRepository, &$applied, &$residual) {
                    $aggregate = $inventoryRepository->findOrCreateForUpdate($itemId, $locationId, null);
                    $before = (int) $aggregate->on_order;
                    $after = max(0, $before - $delta);
                    $realDelta = $before - $after;

                    if ($realDelta !== 0) {
                        $aggregate->on_order = $after;
                        $inventoryRepository->updateStock($aggregate);

                        $movementRepository->create([
                            'item_id'            => $itemId,
                            'location_id'        => $locationId,
                            'bin_id'             => null,
                            'transaction_number' => 'RECONCILE-ON-ORDER',
                            'source'             => 'ORDER_RELEASE',
                            'qty'                => -$realDelta,
                            'balance'            => $after,
                            'transaction_date'   => now(),
                            'created_by'         => 'system',
                        ]);

                        $applied++;
                    }

                    $remaining = $delta - $realDelta;

                    // Kelebihan yang tidak tertampung baris agregat biasanya tersimpan di
                    // baris bin (mis. reservasi transfer draft). Kuras dari sana.
                    if ($remaining > 0) {
                        $binRows = DB::table('inventories')
                            ->where('item_id', $itemId)
                            ->where('location_id', $locationId)
                            ->whereNotNull('bin_id')
                            ->where('on_order', '>', 0)
                            ->orderByDesc('on_order')
                            ->get();

                        foreach ($binRows as $binRow) {
                            if ($remaining <= 0) {
                                break;
                            }

                            $binInv = $inventoryRepository->findOrCreateForUpdate($itemId, $locationId, $binRow->bin_id);
                            $take = min($remaining, (int) $binInv->on_order);

                            if ($take <= 0) {
                                continue;
                            }

                            $binInv->on_order = (int) $binInv->on_order - $take;
                            $inventoryRepository->updateStock($binInv);

                            $movementRepository->create([
                                'item_id'            => $itemId,
                                'location_id'        => $locationId,
                                'bin_id'             => $binRow->bin_id,
                                'transaction_number' => 'RECONCILE-ON-ORDER',
                                'source'             => 'ORDER_RELEASE',
                                'qty'                => -$take,
                                'balance'            => (int) $binInv->on_order,
                                'transaction_date'   => now(),
                                'created_by'         => 'system',
                            ]);

                            $remaining -= $take;
                            $applied++;
                        }
                    }

                    if ($remaining !== 0) {
                        $residual += $remaining;
                    }
                });
            });
        }

        $this->info(sprintf('%d baris dikoreksi.', $applied));

        if ($residual !== 0) {
            $this->warn(sprintf('Sisa %+d unit tidak dapat dikoreksi dari baris agregat (kemungkinan tersimpan di baris bin). Perlu ditinjau manual.', $residual));
        }

        return self::SUCCESS;
    }

    private function buildExpected(ProductRepository $productRepository): array
    {
        $expected = [];

        $kecilId = DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');

        $orders = SalesOrder::with('items')->where('status', 'reserved')->get();

        foreach ($orders as $order) {
            $locationId = $this->resolveLocationId($order, $kecilId);
            if (! $locationId) {
                continue;
            }

            foreach ($order->items as $item) {
                if (! $item->item_id || (int) $item->qty_in_base <= 0) {
                    continue;
                }

                $this->addExpected($expected, $productRepository, $item->item_id, $locationId, (int) $item->qty_in_base);
            }
        }

        $transfers = DB::table('inventory_transfer_items as ti')
            ->join('inventory_transfers as t', 't.id', '=', 'ti.inventory_transfer_id')
            ->whereIn('t.status', ['DRAFT', 'APPROVED'])
            ->select('ti.item_id', 't.source_location_id', DB::raw('SUM(ti.qty) as q'))
            ->groupBy('ti.item_id', 't.source_location_id')
            ->get();

        foreach ($transfers as $row) {
            $key = $row->item_id.'|'.$row->source_location_id;
            $expected[$key] = ($expected[$key] ?? 0) + (int) $row->q;
        }

        return $expected;
    }

    private function addExpected(array &$expected, ProductRepository $productRepository, string $itemId, string $locationId, int $qty): void
    {
        $components = $productRepository->bundleComponentsForVariant($itemId);

        if ($components !== null) {
            foreach ($components as $component) {
                $key = $component['variant_id'].'|'.$locationId;
                $expected[$key] = ($expected[$key] ?? 0) + ($qty * $component['qty']);
            }

            return;
        }

        $key = $itemId.'|'.$locationId;
        $expected[$key] = ($expected[$key] ?? 0) + $qty;
    }

    private function buildActual(): array
    {
        $actual = [];

        $rows = DB::table('inventories')
            ->select('item_id', 'location_id', DB::raw('SUM(on_order) as q'))
            ->groupBy('item_id', 'location_id')
            ->havingRaw('SUM(on_order) <> 0')
            ->get();

        foreach ($rows as $row) {
            $actual[$row->item_id.'|'.$row->location_id] = (int) $row->q;
        }

        return $actual;
    }

    private function resolveLocationId(SalesOrder $order, ?string $kecilId): ?string
    {
        $isManual = in_array($order->source, [null, '', 'manual'], true);

        if ($isManual && $order->location_id) {
            return $order->location_id;
        }

        return $kecilId ?: $order->location_id;
    }
}
