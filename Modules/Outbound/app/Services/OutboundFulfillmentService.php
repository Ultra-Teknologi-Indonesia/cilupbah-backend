<?php

namespace Modules\Outbound\Services;

use Modules\Sales\Models\SalesOrder as Order;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Models\FulfillmentRemoval;
use Modules\Outbound\Repositories\OutboundFulfillmentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Outbound\Services\Logistics\LogisticsGateway;

class OutboundFulfillmentService
{
    public function __construct(
        protected \Modules\Sales\Services\SalesOrderService $orderService,
        protected ShopeeOrderService $shopeeOrderService,
        protected OutboundFulfillmentRepository $fulfillmentRepository,
        protected LogisticsGateway $logisticsGateway,
    ) {}

    public function readyToShip(array $orderIds): array
    {
        $results = [];

        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);

            if (!$order) {
                $results[] = [
                    'order_id'      => $orderId,
                    'salesorder_no' => null,
                    'source'        => null,
                    'status'        => 'failed',
                    'message'       => 'Order tidak ditemukan.',
                ];
                continue;
            }

            try {
                $adapter = $this->logisticsGateway->for($order->source);
                $outcome = $adapter->readyToShip($order);
                $results[] = $this->result(
                    $order,
                    (string) ($outcome['status'] ?? 'failed'),
                    (string) ($outcome['message'] ?? ''),
                );
            } catch (\Throwable $e) {
                Log::error('readyToShip dispatcher gagal untuk order', [
                    'order_id'         => $order->id,
                    'salesorder_no'    => $order->salesorder_no,
                    'source'           => $order->source,
                    'channel_shop_id'  => (string) ($order->channel_shop_id ?? ''),
                    'channel_order_no' => (string) ($order->channel_order_no ?? ''),
                    'error'            => $e->getMessage(),
                ]);
                $results[] = $this->result($order, 'failed', $e->getMessage());
            }
        }

        return $results;
    }

    public function retryPickup(array $orderIds): array
    {
        $results = [];

        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);

            if (! $order) {
                $results[] = ['order_id' => $orderId, 'salesorder_no' => null, 'source' => null, 'status' => 'failed', 'message' => 'Order tidak ditemukan.'];
                continue;
            }

            if ($order->channel_status !== 'RETRY_SHIP') {
                $results[] = $this->result($order, 'skipped', 'Order tidak dalam status RETRY_SHIP.');
                continue;
            }

            $source = strtolower((string) $order->source);

            if ($source !== 'shopee') {
                $results[] = $this->result($order, 'skipped', 'Retry pickup hanya tersedia untuk order Shopee.');
                continue;
            }

            try {
                $this->assertChannelRefs($source, (string) $order->channel_shop_id, (string) $order->channel_order_no);
                $res = $this->shopeeOrderService->retryPickup((string) $order->channel_shop_id, (string) $order->channel_order_no);

                if (! empty($res['updated'])) {
                    $results[] = $this->result($order, 'success', 'Pickup berhasil diatur ulang.');
                } else {
                    $results[] = $this->result($order, 'failed', 'Gagal atur ulang pickup' . (! empty($res['error']) ? " ({$res['error']})" : '') . '.');
                }
            } catch (\Throwable $e) {
                Log::error('retryPickup gagal', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $this->result($order, 'failed', $e->getMessage());
            }
        }

        return $results;
    }

    private function assertChannelRefs(string $source, string $shopId, string $channelOrderNo): void
    {
        if ($shopId === '' || $channelOrderNo === '') {
            throw new \Exception(ucfirst($source) . ': channel_shop_id atau channel_order_no kosong, tidak bisa kirim ke marketplace.');
        }
    }

    private function result(Order $order, string $status, string $message): array
    {
        return [
            'order_id'      => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source'        => $order->source,
            'status'        => $status,
            'message'       => $message,
        ];
    }

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
            'ready-to-ship' => $this->readyToShipStage(),
            'shipped' => $this->shipped(),
            'empty-stock' => $this->emptyStock(),
            'request-cancel' => $this->pendingCancelRequests(),
            default => throw new \Exception("Stage '{$stage}' tidak dikenal."),
        };

        $extraSelects = match ($stage) {
            'finish-pick' => ['picker_name', 'picklist_ref'],
            'finish-pack' => ['packer_name'],
            default       => [],
        };

        return $this->fulfillmentRepository->paginateStage($query, $limit, $extraSelects);
    }

    public function getPickers(?string $locationId, string $role): \Illuminate\Support\Collection
    {
        return $this->fulfillmentRepository->getPickers($locationId, $role);
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
            ->orWhere('channel_order_no', $orderNo)
            ->orWhere('tracking_number', $orderNo)
            ->with([
                'items.product:id,sku,product_id',
                'items.product.product:id,name',
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
                    'sku' => $item->sku,
                    'qty_ordered' => $item->qty_in_base,
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
        $last = Picklist::whereRaw("picklist_no ~ '^PICK-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(picklist_no FROM 6) AS BIGINT) DESC")
            ->value('picklist_no');

        $seq = $last ? ((int) substr($last, 5)) + 1 : (Picklist::count() + 1);

        return 'PICK-' . str_pad((string) $seq, 9, '0', STR_PAD_LEFT);
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
            ->whereNotNull('handed_to_warehouse_at')
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
        return Order::where(function ($q) {
            $q->where('status', 'packed')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'cancelled')
                        ->whereNull('cancel_dismissed_at')
                        ->whereHas('packlist', fn ($pq) => $pq->where('status', Packlist::STATUS_COMPLETED));
                });
        })->whereDoesntHave('shipmentOrders');
    }

    private function readyToShipStage()
    {
        return Order::where(function ($q) {
            $q->where('status', 'packed')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'cancelled')
                        ->whereNull('cancel_dismissed_at');
                });
        })->whereHas('shipmentOrders', function ($q) {
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

    public function deleteOrderFromFulfillment(string $orderId, ?string $reason, ?string $removedBy): void
    {
        $order = Order::findOrFail($orderId);

        if ($order->status === 'shipped') {
            throw new \Exception('Pesanan sudah dikirim — tidak bisa dihapus.');
        }

        $removedBy = $removedBy ?: 'system';

        $shipmentId = ShipmentOrder::where('order_id', $order->id)->value('shipment_id');

        if ($shipmentId) {
            app(\Modules\Outbound\Services\ShipmentService::class)->removeOrders($shipmentId, [$order->id]);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_SHIPPING, $removedBy, $reason, false);

            return;
        }

        $activePacklist = Packlist::where('order_id', $order->id)
            ->where('status', '!=', Packlist::STATUS_CANCELLED)
            ->first();

        if ($activePacklist) {
            app(\Modules\Outbound\Services\PacklistService::class)->revert($activePacklist->id);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_PACKING, $removedBy, $reason, false);

            return;
        }

        $picklistId = PicklistItem::where('order_id', $order->id)->value('picklist_id');

        if ($picklistId) {
            $reversed = app(\Modules\Outbound\Services\PicklistService::class)
                ->failPickOrder($picklistId, $order->id, $removedBy, (string) $reason);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_PICKING, $removedBy, $reason, $reversed);

            return;
        }

        throw new \Exception('Pesanan belum masuk proses picking/packing/pengiriman — tidak ada yang bisa dikembalikan.');
    }

    private function logRemoval(string $orderId, string $stage, string $removedBy, ?string $reason, bool $reversedStock): void
    {
        FulfillmentRemoval::create([
            'order_id'       => $orderId,
            'stage'          => $stage,
            'removed_by'     => $removedBy,
            'reason'         => $reason,
            'reversed_stock' => $reversedStock,
        ]);
    }
}
