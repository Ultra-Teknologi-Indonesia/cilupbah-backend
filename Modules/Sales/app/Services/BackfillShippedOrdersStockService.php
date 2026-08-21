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

    public function getEligibleOrders(?string $orderNo = null, ?int $limit = null): Collection
    {
        $query = SalesOrder::query()
            ->with(['items.product'])
            ->where('is_shadow', false)
            ->where('is_canceled', false)
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

        if ($orderNo) {
            $query->where(function ($q) use ($orderNo) {
                $q->where('salesorder_no', $orderNo)
                    ->orWhere('channel_order_no', $orderNo)
                    ->orWhere('salesorder_no', 'like', "%{$orderNo}%");
            });
        }

        $query->orderBy('created_at', 'asc');

        if ($limit && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function backfillOrder(SalesOrder $order, bool $dryRun = false): array
    {
        $locationId = $this->resolveLocationId($order);
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

                $allocatedBins = $this->allocateBinsForItem($compItemId, $locationId, $compQty);

                foreach ($allocatedBins as $alloc) {
                    $deductions[] = [
                        'order_item_id' => $orderItem->id,
                        'item_id' => $compItemId,
                        'sku' => $sku,
                        'location_id' => $locationId,
                        'bin_id' => $alloc['bin_id'],
                        'bin_code' => $alloc['bin_code'],
                        'qty' => $alloc['qty'],
                    ];

                    if (! $dryRun) {
                        $this->stockService->consumeFromBin(
                            $sku,
                            $compItemId,
                            $locationId,
                            $alloc['bin_id'],
                            $alloc['qty'],
                            $order->salesorder_no,
                            'ORDER_COMPLETE_OUT',
                            'system:backfill',
                            true,
                            $transactionDate
                        );

                        OrderBinAllocation::create([
                            'order_id' => $order->id,
                            'order_item_id' => $orderItem->id,
                            'item_id' => $compItemId,
                            'location_id' => $locationId,
                            'bin_id' => $alloc['bin_id'],
                            'qty' => $alloc['qty'],
                            'completed_by' => null,
                            'completed_at' => $transactionDate,
                        ]);
                    }
                }
            }
        }

        return [
            'success' => true,
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'deductions' => $deductions,
        ];
    }

    private function resolveLocationId(SalesOrder $order): ?string
    {
        if ($order->location_id) {
            return $order->location_id;
        }

        return DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id') ?: DB::table('locations')->orderBy('id')->value('id');
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

    private function allocateBinsForItem(string $itemId, string $locationId, int $requiredQty): array
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

            $take = min($outstanding, (int) $bin->on_hand);
            if ($take > 0) {
                $allocations[] = [
                    'bin_id' => $bin->bin_id,
                    'bin_code' => $bin->bin_final_code,
                    'qty' => $take,
                ];
                $outstanding -= $take;
            }
        }

        if ($outstanding <= 0) {
            return $allocations;
        }

        $fallbackBin = DB::table('inventories as i')
            ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.item_id', $itemId)
            ->where('i.location_id', $locationId)
            ->where('b.is_inbound', false)
            ->select('i.bin_id', 'b.bin_final_code')
            ->first();

        if (! $fallbackBin) {
            $fallbackBin = DB::table('location_bins')
                ->where('location_id', $locationId)
                ->where('is_inbound', false)
                ->orderBy('bin_final_code')
                ->select('id as bin_id', 'bin_final_code')
                ->first();
        }

        if (! $fallbackBin) {
            $fallbackBin = DB::table('location_bins')
                ->where('location_id', $locationId)
                ->orderBy('id')
                ->select('id as bin_id', 'bin_final_code')
                ->first();
        }

        if ($fallbackBin) {
            $alreadyUsed = false;
            foreach ($allocations as &$alloc) {
                if ($alloc['bin_id'] === $fallbackBin->bin_id) {
                    $alloc['qty'] += $outstanding;
                    $alreadyUsed = true;
                    break;
                }
            }

            if (! $alreadyUsed) {
                $allocations[] = [
                    'bin_id' => $fallbackBin->bin_id,
                    'bin_code' => $fallbackBin->bin_final_code,
                    'qty' => $outstanding,
                ];
            }
        }

        return $allocations;
    }
}
