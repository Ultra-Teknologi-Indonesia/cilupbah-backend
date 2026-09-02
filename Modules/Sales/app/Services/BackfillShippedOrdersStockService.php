<?php

namespace Modules\Sales\Services;

use App\Support\ChannelWarehousePolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Jobs\ProcessPicklistCompleteJob;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Models\OrderBinAllocation;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;

class BackfillShippedOrdersStockService
{
    public function __construct(
        protected StockService $stockService,
        protected ChannelWarehousePolicy $channelWarehousePolicy,
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
                    ->from('picklist_items')
                    ->whereColumn('picklist_items.order_id', 'sales_orders.id')
                    ->whereIn('item_status', [
                        PicklistItem::STATUS_SHORT,
                        PicklistItem::STATUS_REJECTED,
                    ]);
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
        $hasFailedPickItems = DB::table('picklist_items')
            ->where('order_id', $order->id)
            ->whereIn('item_status', [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED])
            ->exists();
        $hasMovements = DB::table('inventory_movements')
            ->where('transaction_number', $order->salesorder_no)
            ->whereIn('source', ['ORDER_COMPLETE_OUT', 'PICKING'])
            ->exists();

        if ($hasAllocations || $hasPickedItems || $hasFailedPickItems || $hasMovements) {
            if (! $this->hasCompletePhysicalProcessing($order)) {
                return [
                    'success' => false,
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'message' => 'Backfill dihentikan karena histori pemotongan stok hanya sebagian dan perlu rekonsiliasi.',
                    'deductions' => [],
                    'shortages' => [['reason' => 'PARTIAL_PHYSICAL_PROCESSING']],
                ];
            }

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

        if ($this->channelWarehousePolicy->isChannelSource($order->source)
            && (string) $order->location_id !== (string) $locationId
        ) {
            return [
                'success' => false,
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'message' => 'Backfill dihentikan karena lokasi order channel bukan Gudang Kecil.',
                'deductions' => [],
                'shortages' => [[
                    'reason' => 'INVALID_CHANNEL_LOCATION',
                    'location_id' => $order->location_id,
                    'required_location_id' => $locationId,
                ]],
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
                        'reason' => $allocation['reason'] ?? 'NO_VALID_ASSIGNED_BIN',
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
                'message' => 'Backfill tidak dilakukan karena rak alokasi SKU tidak ditemukan atau tidak valid. Stok inbound/DEFAULT tidak pernah digunakan.',
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

            $completedPicklists = $this->markPicklistItemsProcessedExternally($order, $deductions);
        } else {
            $completedPicklists = [];
        }

        return [
            'success' => true,
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'deductions' => $deductions,
            'picklists_completed' => $completedPicklists,
        ];
    }

    private function hasCompletePhysicalProcessing(SalesOrder $order): bool
    {
        $requiredByItem = [];

        foreach ($order->items as $orderItem) {
            if (! $orderItem->item_id || (int) $orderItem->qty_in_base <= 0) {
                continue;
            }

            foreach ($this->resolveComponents($orderItem->item_id, (int) $orderItem->qty_in_base) as $component) {
                $requiredByItem[$component['item_id']] = (int) ($requiredByItem[$component['item_id']] ?? 0) + (int) $component['qty'];
            }
        }

        if ($requiredByItem === []) {
            return true;
        }

        $processedByItem = DB::table('inventory_movements')
            ->where('transaction_number', $order->salesorder_no)
            ->whereIn('source', ['ORDER_COMPLETE_OUT', 'PICKING'])
            ->where('qty', '<', 0)
            ->select('item_id')
            ->selectRaw('SUM(ABS(qty)) as processed_qty')
            ->groupBy('item_id')
            ->pluck('processed_qty', 'item_id');

        $allocatedByItem = DB::table('order_bin_allocations')
            ->where('order_id', $order->id)
            ->select('item_id')
            ->selectRaw('SUM(qty) as allocated_qty')
            ->groupBy('item_id')
            ->pluck('allocated_qty', 'item_id');

        foreach ($requiredByItem as $itemId => $requiredQty) {

            $processedQty = max(
                (int) ($processedByItem[$itemId] ?? 0),
                (int) ($allocatedByItem[$itemId] ?? 0),
            );

            if ($processedQty < $requiredQty) {
                return false;
            }
        }

        return true;
    }

    private function markPicklistItemsProcessedExternally(SalesOrder $order, array $deductions): array
    {
        $itemIds = collect($deductions)
            ->pluck('item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        $items = DB::table('picklist_items as pi')
            ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
            ->where('pi.order_id', $order->id)
            ->whereIn('pi.item_id', $itemIds)
            ->whereIn('p.status', [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS])
            ->where('pi.qty_picked', 0)
            ->where(function ($query) {
                $query->whereNull('pi.item_status')
                    ->orWhereNotIn('pi.item_status', [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED]);
            })
            ->lockForUpdate()
            ->get(['pi.id', 'pi.picklist_id']);

        if ($items->isEmpty()) {
            return [];
        }

        $now = now();
        DB::table('picklist_items')
            ->whereIn('id', $items->pluck('id')->all())
            ->update([
                'item_status' => PicklistItem::STATUS_PROCESSED_EXTERNALLY,
                'updated_at' => $now,
            ]);

        $completedPicklists = [];
        foreach ($items->pluck('picklist_id')->unique() as $picklistId) {
            $picklist = Picklist::query()
                ->with('items')
                ->lockForUpdate()
                ->find($picklistId);

            if (! $picklist || ! in_array($picklist->status, [Picklist::STATUS_DRAFT, Picklist::STATUS_IN_PROGRESS], true)) {
                continue;
            }

            if ($picklist->items->isEmpty() || ! $picklist->items->every(fn (PicklistItem $item): bool => $item->isResolved())) {
                continue;
            }

            $picklist->forceFill([
                'status' => Picklist::STATUS_COMPLETED,
                'completed_at' => $now,
            ])->save();

            ProcessPicklistCompleteJob::dispatch((string) $picklist->id)->afterCommit();
            $completedPicklists[] = (string) $picklist->id;
        }

        return $completedPicklists;
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
        if ($requiredQty <= 0) {
            return ['allocations' => [], 'shortage' => 0];
        }

        $assignedBin = DB::table('sku_rack_assignments as assignment')
            ->join('location_bins as bin', 'bin.id', '=', 'assignment.bin_id')
            ->where('assignment.item_id', $itemId)
            ->where('assignment.location_id', $locationId)
            ->where('bin.location_id', $locationId)
            ->where('bin.is_inbound', false)
            ->whereRaw("UPPER(TRIM(COALESCE(bin.bin_final_code, bin.bin_code, ''))) <> 'DEFAULT'")
            ->lockForUpdate()
            ->first([
                'assignment.bin_id',
                'bin.bin_final_code',
            ]);

        if (! $assignedBin) {
            return [
                'allocations' => [],
                'shortage' => $requiredQty,
                'reason' => 'NO_VALID_ASSIGNED_BIN',
            ];
        }

        $inventory = DB::table('inventories')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $assignedBin->bin_id)
            ->where('batch_no', '')
            ->where('serial_no', '')
            ->lockForUpdate()
            ->first(['id', 'on_hand']);

        $plannedKey = $itemId.'|'.$assignedBin->bin_id;
        $plannedQty = (int) ($plannedQtyByBin[$plannedKey] ?? 0);
        $available = max(0, (int) ($inventory->on_hand ?? 0) - $plannedQty);

        if ($available < $requiredQty) {
            return [
                'allocations' => [],
                'shortage' => $requiredQty,
                'reason' => 'INSUFFICIENT_ASSIGNED_BIN',
            ];
        }

        $plannedQtyByBin[$plannedKey] = $plannedQty + $requiredQty;

        return [
            'allocations' => [[
                'bin_id' => $assignedBin->bin_id,
                'bin_code' => $assignedBin->bin_final_code,
                'qty' => $requiredQty,
            ]],
            'shortage' => 0,
            'reason' => null,
        ];
    }
}
