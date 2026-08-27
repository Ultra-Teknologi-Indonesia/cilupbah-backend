<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\OrderBinAllocation;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;

class BackfillShippedOrdersStockService
{
    public function __construct(
        protected StockService $stockService,
    ) {}

    public function getEligibleOrdersQuery(?string $orderNo = null, ?string $since = null, ?int $limit = null)
    {
        $query = SalesOrder::query()
            ->with(['items.product'])
            ->where('is_shadow', false)
            ->where('is_canceled', false)

            ->whereNull('handed_to_warehouse_at')
            ->where(function ($q) {
                $q->whereIn('status', ['shipped', 'completed', 'delivered'])
                    ->orWhereIn('channel_status', ['SHIPPED', 'COMPLETED', 'DELIVERED', 'TO_CONFIRM_RECEIVE']);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('order_bin_allocations')
                    ->whereColumn('order_bin_allocations.order_id', 'sales_orders.id');
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('picklist_items')
                    ->whereColumn('picklist_items.order_id', 'sales_orders.id')
                    ->where('qty_picked', '>', 0);
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('inventory_movements')
                    ->whereColumn('inventory_movements.transaction_number', 'sales_orders.salesorder_no')
                    ->whereIn('source', ['ORDER_COMPLETE_OUT', 'PICKING']);
            });

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        if ($orderNo) {
            $query->where(function ($q) use ($orderNo) {
                $q->where('salesorder_no', $orderNo)
                    ->orWhere('channel_order_no', $orderNo)
                    ->orWhere('salesorder_no', 'like', "%{$orderNo}%");
            });
        }

        $query->orderBy('id', 'asc');

        if ($limit && $limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    public function getEligibleOrders(?string $orderNo = null, ?int $limit = null, ?string $since = null): Collection
    {
        return $this->getEligibleOrdersQuery($orderNo, $since, $limit)->get();
    }

    public function backfillOrder(SalesOrder $order, bool $dryRun = false): array
    {
        return DB::transaction(function () use ($order, $dryRun) {
            $lockedOrder = SalesOrder::query()
                ->with(['items.product'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->backfillLockedOrder($lockedOrder, $dryRun);
        });
    }

    private function backfillLockedOrder(SalesOrder $order, bool $dryRun): array
    {
        $hasAllocations = DB::table('order_bin_allocations')->where('order_id', $order->id)->exists();
        $hasPickedItems = DB::table('picklist_items')->where('order_id', $order->id)->where('qty_picked', '>', 0)->exists();
        $hasMovements = DB::table('inventory_movements')
            ->where('transaction_number', $order->salesorder_no)
            ->whereIn('source', ['ORDER_COMPLETE_OUT', 'PICKING'])
            ->exists();

        if ($hasAllocations || $hasPickedItems || $hasMovements) {
            return [
                'success' => true,
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'message' => 'Pesanan sudah pernah diproses potong stok fisik sebelumnya (di-skip).',
                'deductions' => [],
            ];
        }

        if ($order->handed_to_warehouse_at !== null) {
            return [
                'success' => true,
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'message' => 'Pesanan sudah diserahkan ke warehouse lokal; backfill channel di-skip untuk mencegah potong stok ganda.',
                'deductions' => [],
            ];
        }

        $locationId = $this->resolveLocationId();
        if (! $locationId) {
            return [
                'success' => false,
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'message' => 'Lokasi gudang tidak ditemukan.',
                'deductions' => [],
            ];
        }

        $deductions = [];
        $shortages = [];
        $plannedQtyByBin = [];
        $transactionDate = $order->pickup_done_time ?: ($order->transaction_date ?: $order->created_at);

        foreach ($order->items as $orderItem) {
            $itemId = $orderItem->item_id;
            $qty = (int) $orderItem->qty_in_base;

            if (! $itemId || $qty <= 0) {
                continue;
            }

            $components = $this->resolveComponents($itemId, $qty);

            foreach ($components as $component) {
                $compItemId = $component['item_id'];
                $compQty = $component['qty'];
                $sku = $component['sku'] ?: ($orderItem->sku ?: "item:{$compItemId}");

                $allocation = $this->allocateBinsForItem($compItemId, $locationId, $compQty, $plannedQtyByBin);

                if ($allocation['shortage'] > 0) {
                    $shortages[] = [
                        'item_id' => $compItemId,
                        'sku' => $sku,
                        'qty_required' => $compQty,
                        'qty_allocated' => $compQty - $allocation['shortage'],
                        'qty_short' => $allocation['shortage'],
                    ];
                    continue;
                }

                foreach ($allocation['allocations'] as $alloc) {
                    $deductions[] = [
                        'order_item_id' => $orderItem->id,
                        'item_id' => $compItemId,
                        'sku' => $sku,
                        'location_id' => $locationId,
                        'bin_id' => $alloc['bin_id'],
                        'bin_code' => $alloc['bin_code'],
                        'qty' => $alloc['qty'],
                    ];
                }
            }
        }

        if ($shortages !== []) {
            return [
                'success' => false,
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'message' => 'Backfill tidak dilakukan karena stok final/rak penyimpanan tidak mencukupi. Stok inbound/DEFAULT tidak pernah digunakan.',
                'deductions' => [],
                'shortages' => $shortages,
            ];
        }

        if (! $dryRun) {
            foreach ($deductions as $deduction) {
                $this->stockService->consumeFromBin(
                    $deduction['sku'],
                    $deduction['item_id'],
                    $locationId,
                    $deduction['bin_id'],
                    $deduction['qty'],
                    $order->salesorder_no,
                    'ORDER_COMPLETE_OUT',
                    'system:backfill',
                    false,
                    $transactionDate,
                );

                OrderBinAllocation::create([
                    'order_id' => $order->id,
                    'order_item_id' => $deduction['order_item_id'],
                    'item_id' => $deduction['item_id'],
                    'location_id' => $locationId,
                    'bin_id' => $deduction['bin_id'],
                    'qty' => $deduction['qty'],
                    'completed_by' => null,
                    'completed_at' => $transactionDate,
                ]);
            }
        }

        return [
            'success' => true,
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'deductions' => $deductions,
        ];
    }

    private function resolveLocationId(): ?string
    {

        return Location::getOfficialSmallWarehouseId();
    }

    private function resolveComponents(string $itemId, int $parentQty): array
    {
        $bundleRows = DB::table('product_bundle_items as pbi')
            ->join('product_variants as pv', 'pv.product_id', '=', 'pbi.bundle_product_id')
            ->where('pv.id', $itemId)
            ->select('pbi.component_variant_id', 'pbi.qty')
            ->get();

        if ($bundleRows->isEmpty()) {
            $sku = DB::table('product_variants')->where('id', $itemId)->value('sku') ?? "item:{$itemId}";

            return [[
                'item_id' => $itemId,
                'qty' => $parentQty,
                'sku' => $sku,
            ]];
        }

        $components = [];
        foreach ($bundleRows as $row) {
            $compSku = DB::table('product_variants')->where('id', $row->component_variant_id)->value('sku') ?? "item:{$row->component_variant_id}";
            $components[] = [
                'item_id' => $row->component_variant_id,
                'qty' => $parentQty * (int) $row->qty,
                'sku' => $compSku,
            ];
        }

        return $components;
    }

    private function allocateBinsForItem(string $itemId, string $locationId, int $requiredQty, array &$plannedQtyByBin): array
    {
        $allocations = [];
        $outstanding = $requiredQty;

        $binsWithStock = DB::table('inventories as i')
            ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.item_id', $itemId)
            ->where('i.location_id', $locationId)
            ->where('b.is_inbound', false)
            ->where('i.on_hand', '>', 0)
            ->orderByDesc('i.on_hand')
            ->select('i.bin_id', 'b.bin_final_code', 'i.on_hand')
            ->get();

        foreach ($binsWithStock as $bin) {
            if ($outstanding <= 0) {
                break;
            }

            $remainingInBin = max(0, (int) $bin->on_hand - (int) ($plannedQtyByBin[$bin->bin_id] ?? 0));
            $take = min($outstanding, $remainingInBin);
            if ($take > 0) {
                $allocations[] = [
                    'bin_id' => $bin->bin_id,
                    'bin_code' => $bin->bin_final_code,
                    'qty' => $take,
                ];
                $plannedQtyByBin[$bin->bin_id] = (int) ($plannedQtyByBin[$bin->bin_id] ?? 0) + $take;
                $outstanding -= $take;
            }
        }

        if ($outstanding <= 0) {
            return ['allocations' => $allocations, 'shortage' => 0];
        }

        return ['allocations' => [], 'shortage' => $outstanding];
    }
}
