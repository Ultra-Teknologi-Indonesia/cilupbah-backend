<?php

namespace Modules\Outbound\Services;

use Modules\Sales\Models\SalesOrder as Order;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Inventory\Models\Inventory;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Facades\DB;

class OutboundFulfillmentService
{
    public function __construct(
        protected \Modules\Sales\Services\SalesOrderService $orderService,
    ) {}

    public function getOrdersByStage(string $stage, int $limit = 10)
    {
        $query = match ($stage) {
            'ready-to-process' => $this->readyToProcess(),
            'ready-to-pick' => $this->readyToPick(),
            'on-picking' => $this->onPicking(),
            'finish-pick' => $this->finishPick(),
            'failed-pick' => $this->failedPick(),
            'on-packing' => $this->onPacking(),
            'finish-pack' => $this->finishPack(),
            'ready-to-ship' => $this->readyToShip(),
            'shipped' => $this->shipped(),
            'empty-stock' => $this->emptyStock(),
            'request-cancel' => $this->pendingCancelRequests(),
            default => throw new \Exception("Stage '{$stage}' tidak dikenal."),
        };

        return QueryBuilder::for($query)
            ->allowedFilters(
                AllowedFilter::partial('q', 'salesorder_no'),
                AllowedFilter::exact('source'),
                AllowedFilter::exact('location_id'),
            )
            ->allowedSorts('transaction_date', 'created_at', 'grand_total')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function changeLocation(string $orderId, string $locationId, string $changedBy): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if (!in_array($order->status, ['pending', 'reserved'])) {
            throw new \Exception("Order hanya bisa dipindah lokasi saat status pending/reserved (saat ini: {$order->status}).");
        }

        return $this->orderService->relocateOrder($order, $locationId);
    }

    public function findOrderByNo(string $orderNo): ?Order
    {
        return Order::where('salesorder_no', $orderNo)
            ->with([
                'items.product:id,sku,product_id',
                'items.product.product:id,product_name',
                'location:id,location_name,location_code',
            ])
            ->first();
    }

    public function moveToReadyToPick(string $orderId, string $locationId, string $movedBy): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if ($order->status !== 'reserved') {
            throw new \Exception("Order harus berstatus 'reserved' untuk dipindah ke ready-to-pick (saat ini: {$order->status}).");
        }

        $existing = PicklistItem::where('order_id', $orderId)
            ->whereHas('picklist', fn ($q) => $q->whereNotIn('status', [Picklist::STATUS_CANCELLED, Picklist::STATUS_FAILED]))
            ->exists();

        if ($existing) {
            throw new \Exception('Order sudah memiliki picklist aktif.');
        }

        DB::transaction(function () use ($order, $locationId, $movedBy) {
            $picklist = Picklist::create([
                'picklist_no' => $this->generatePicklistNo(),
                'location_id' => $locationId,
                'status' => Picklist::STATUS_DRAFT,
                'created_by' => $movedBy,
            ]);

            foreach ($order->items as $item) {
                PicklistItem::create([
                    'picklist_id' => $picklist->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'qty_ordered' => $item->qty,
                    'qty_picked' => 0,
                ]);
            }
        });

        return $order->fresh();
    }

    public function moveToReadyToProcess(string $orderId): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if ($order->status !== 'reserved') {
            throw new \Exception("Order harus berstatus 'reserved' untuk dipindah ke ready-to-process (saat ini: {$order->status}).");
        }

        PicklistItem::where('order_id', $orderId)
            ->whereHas('picklist', fn ($q) => $q->where('status', Picklist::STATUS_DRAFT))
            ->delete();

        return $order->fresh();
    }

    private function generatePicklistNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "PK-{$date}-";

        $last = Picklist::where('picklist_no', 'like', "{$prefix}%")
            ->orderByDesc('picklist_no')
            ->value('picklist_no');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    public function requestCancelOrder(string $orderId, ?string $reason = null, ?string $requestedBy = null): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if (in_array($order->status, ['shipped', 'cancelled'])) {
            throw new \Exception("Order dengan status '{$order->status}' tidak bisa di-request cancel.");
        }

        $order->update([
            'cancel_request_reason' => $reason,
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $requestedBy,
        ]);

        return $order->fresh();
    }

    private function readyToProcess()
    {
        return Order::where('status', 'reserved')
            ->whereDoesntHave('picklistItems');
    }

    private function readyToPick()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_DRAFT));
            });
    }

    private function onPicking()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_IN_PROGRESS));
            });
    }

    private function finishPick()
    {
        return Order::where('status', 'picked')
            ->whereDoesntHave('packlist', fn ($q) => $q->whereIn('status', [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS, Packlist::STATUS_COMPLETED]));
    }

    private function failedPick()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_FAILED));
            });
    }

    private function onPacking()
    {
        return Order::where('status', 'picked')
            ->whereHas('packlist', fn ($q) => $q->where('status', Packlist::STATUS_IN_PROGRESS));
    }

    private function finishPack()
    {
        return Order::where('status', 'packed')
            ->whereDoesntHave('shipmentOrders');
    }

    private function readyToShip()
    {
        return Order::where('status', 'packed')
            ->whereHas('shipmentOrders', function ($q) {
                $q->whereHas('shipment', fn ($sq) => $sq->where('status', 'SCHEDULED'));
            });
    }

    private function shipped()
    {
        return Order::where('status', 'shipped');
    }

    private function emptyStock()
    {
        return Order::where('status', 'reserved')
            ->whereHas('items', function ($q) {
                $q->whereDoesntHave('inventory', function ($iq) {
                    $iq->where('available', '>', 0)
                        ->whereRaw('(sales_orders.location_id IS NULL OR inventories.location_id = sales_orders.location_id)');
                });
            });
    }

    private function pendingCancelRequests()
    {
        return Order::whereNotNull('cancel_requested_at')
            ->whereNotIn('status', ['cancelled']);
    }
}
