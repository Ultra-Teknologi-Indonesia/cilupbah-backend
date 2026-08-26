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
        {--show-all : Tampilkan semua baris on_order meskipun tidak ada selisih}
        {--sku= : Filter berdasarkan SKU tertentu}
        {--fix : Terapkan koreksi. Tanpa flag ini command hanya melapor (DRY-RUN).}';

    protected $description = 'Bandingkan on_order terhadap reservasi sah (pesanan reserved + transfer draft) dan laporkan/koreksi selisihnya.';

    public function handle(
        InventoryRepository $inventoryRepository,
        InventoryMovementRepository $movementRepository,
        ProductRepository $productRepository,
    ): int {
        $fix = (bool) $this->option('fix');
        $showAll = (bool) $this->option('show-all');
        $skuFilter = $this->option('sku') ? strtoupper(trim((string) $this->option('sku'))) : null;

        $expected = $this->buildExpected($productRepository);
        $actual = $this->buildActual();

        $keys = array_unique(array_merge(array_keys($expected), array_keys($actual)));
        sort($keys);

        $itemIds = array_unique(array_map(fn ($k) => explode('|', $k)[0], $keys));
        $locationIds = array_unique(array_map(fn ($k) => explode('|', $k)[1], $keys));

        $variantMap = DB::table('product_variants as pv')
            ->leftJoin('products as p', 'p.id', '=', 'pv.product_id')
            ->whereIn('pv.id', $itemIds)
            ->select('pv.id', 'pv.sku', 'p.name as product_name')
            ->get()
            ->keyBy('id');

        $locationMap = DB::table('locations')
            ->whereIn('id', $locationIds)
            ->select('id', 'location_code', 'location_name')
            ->get()
            ->keyBy('id');

        $drifts = [];
        $allRows = [];
        $totalDrift = 0;

        foreach ($keys as $key) {
            [$itemId, $locationId] = explode('|', $key);
            $variant = $variantMap[$itemId] ?? null;
            $location = $locationMap[$locationId] ?? null;

            $sku = $variant->sku ?? $itemId;
            $name = $variant->product_name ?? '—';
            $locCode = $location->location_code ?? $locationId;

            if ($skuFilter && ! str_contains(strtoupper($sku), $skuFilter)) {
                continue;
            }

            $exp = $expected[$key] ?? 0;
            $act = $actual[$key] ?? 0;
            $drift = $act - $exp;

            $statusStr = $drift === 0 ? '<fg=green>MATCH (0)</>' : ($drift > 0 ? "<fg=yellow;options=bold>OVER (+{$drift})</>" : "<fg=red;options=bold>UNDER ({$drift})</>");

            $row = [
                'SKU' => $sku,
                'Nama Produk' => mb_strimwidth($name, 0, 35, '...'),
                'Lokasi' => $locCode,
                'Expected (Order Sah)' => $exp,
                'Aktual (on_order DB)' => $act,
                'Status / Drift' => $statusStr,
            ];

            $allRows[] = $row;

            if ($exp !== $act) {
                $drifts[$key] = ['expected' => $exp, 'actual' => $act, 'drift' => $drift];
                $totalDrift += $drift;
            }
        }

        $this->line('===============================================================');
        $this->line('  DRY-RUN / INSPEKSI ANGKA ON_ORDER PER SKU');
        $this->line('===============================================================');
        $this->line('Mode : ' . ($fix ? '<fg=red;options=bold>FIX (KOREKSI DATABASE)</>' : '<fg=yellow;options=bold>DRY-RUN / INSPECTION ONLY (AMAN)</>'));
        if ($skuFilter) {
            $this->line("Filter SKU : {$skuFilter}");
        }
        $this->newLine();

        if ($showAll && ! empty($allRows)) {
            $this->table(['SKU', 'Nama Produk', 'Lokasi', 'Expected (Order Sah)', 'Aktual (on_order DB)', 'Status / Drift'], $allRows);
            $this->newLine();
        }

        if (empty($drifts)) {
            $this->info('✅ on_order sudah konsisten dengan reservasi sah. Tidak ada selisih (Drift = 0).');
            if (! $showAll) {
                $this->line('💡 Tip: Gunakan flag <fg=cyan>--show-all</> untuk melihat rincian seluruh SKU yang sedang memiliki on_order.');
            }
            return self::SUCCESS;
        }

        if (! $showAll) {
            $driftRows = [];
            foreach ($drifts as $key => $d) {
                [$itemId, $locationId] = explode('|', $key);
                $variant = $variantMap[$itemId] ?? null;
                $location = $locationMap[$locationId] ?? null;
                $driftRows[] = [
                    'SKU' => $variant->sku ?? $itemId,
                    'Lokasi' => $location->location_code ?? $locationId,
                    'Expected' => $d['expected'],
                    'Aktual' => $d['actual'],
                    'Drift' => sprintf('%+d', $d['drift']),
                ];
            }
            $this->table(['SKU', 'Lokasi', 'Expected', 'Aktual', 'Drift'], $driftRows);
        }

        $this->warn(sprintf('⚠️ Ditemukan %d SKU selisih, total drift %+d unit.', count($drifts), $totalDrift));

        if (! $fix) {
            $this->line('Jalankan ulang dengan flag <fg=cyan>--fix</> untuk menerapkan koreksi ke database.');
            return self::SUCCESS;
        }

        $applied = 0;
        $residual = 0;

        foreach ($drifts as $key => $d) {
            [$itemId, $locationId] = explode('|', $key);
            $delta = $d['drift'];

            $this->withStockLock($itemId, $locationId, function () use ($itemId, $locationId, $delta, $d, $inventoryRepository, $movementRepository, &$applied, &$residual) {
                DB::transaction(function () use ($itemId, $locationId, $delta, $d, $inventoryRepository, $movementRepository, &$applied, &$residual) {
                    // An under-reserved row needs an operational investigation;
                    // inventing an ORDER_RELEASE (or increasing on_order) would
                    // make the ledger less trustworthy. This command only
                    // removes excess on_order.
                    if ($delta <= 0) {
                        $residual += $delta;
                        return;
                    }

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

        $orders = SalesOrder::with('items')
            ->whereIn('status', ['pending', 'reserved', 'picked', 'packed'])
            ->where('is_canceled', false)
            ->get();

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

    private function resolveLocationId(object $order, ?string $kecilId): ?string
    {
        $source = $order->source ?? null;
        $locationId = $order->location_id ?? null;

        $isManual = in_array($source, [null, '', 'manual'], true);

        if ($isManual && $locationId) {
            return $locationId;
        }

        return $kecilId ?: $locationId;
    }
}
