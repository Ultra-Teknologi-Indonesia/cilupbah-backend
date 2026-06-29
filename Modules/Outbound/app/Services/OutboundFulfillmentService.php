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
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\LazadaOrderService;

class OutboundFulfillmentService
{
    public function __construct(
        protected \Modules\Sales\Services\SalesOrderService $orderService,
        protected ShopeeOrderService $shopeeOrderService,
        protected TikTokOrderService $tiktokOrderService,
        protected LazadaOrderService $lazadaOrderService,
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

            $source = strtolower((string) $order->source);
            $shopId = (string) ($order->channel_shop_id ?? '');
            $channelOrderNo = (string) ($order->channel_order_no ?? '');

            if ($source === 'tiktok' && strtoupper((string) $order->fulfillment_type) === 'FULFILLMENT_BY_TIKTOK') {
                $results[] = $this->result($order, 'failed', 'TikTok: order ini FULFILLMENT_BY_TIKTOK (FBT) — RTS dikelola oleh TikTok, bukan seller.');
                continue;
            }

            try {
                switch ($source) {
                    case 'shopee':
                        $this->assertChannelRefs($source, $shopId, $channelOrderNo);
                        $ship = $this->shopeeOrderService->shipOrder($shopId, $channelOrderNo);
                        if (!empty($ship['shipped'])) {
                            $results[] = $this->result($order, 'success', 'Shopee: berhasil dikirim (RTS).');
                        } else {
                            $err = is_array($ship) ? ($ship['error'] ?? null) : null;
                            $results[] = $this->result($order, 'failed', 'Shopee: gagal ship_order' . ($err ? " ({$err})" : '') . '.');
                        }
                        break;

                    case 'tiktok':
                        $this->assertChannelRefs($source, $shopId, $channelOrderNo);
                        $rts = $this->tiktokOrderService->readyToShip($shopId, $channelOrderNo);
                        if (!empty($rts['shipped'])) {
                            $results[] = $this->result($order, 'success', $rts['message'] ?? 'TikTok: RTS berhasil.');
                        } else {
                            $results[] = $this->result($order, 'failed', $rts['message'] ?? 'TikTok: RTS gagal.');
                        }
                        break;

                    case 'lazada':
                        $this->assertChannelRefs($source, $shopId, $channelOrderNo);
                        $this->lazadaOrderService->packOrder($shopId, $channelOrderNo);
                        $results[] = $this->result($order, 'success', 'Lazada: berhasil di-pack & RTS.');
                        break;

                    default:

                        $order->update(['status' => 'ready-to-ship']);
                        $results[] = $this->result($order, 'skipped', 'Order manual/non-marketplace: ditandai siap dikirim secara lokal.');
                        break;
                }
            } catch (\Throwable $e) {
                Log::error('readyToShip dispatcher gagal untuk order', [
                    'order_id'         => $order->id,
                    'salesorder_no'    => $order->salesorder_no,
                    'source'           => $source,
                    'channel_shop_id'  => $shopId,
                    'channel_order_no' => $channelOrderNo,
                    'error'            => $e->getMessage(),
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

        return QueryBuilder::for($query->with(['items', 'items.product.media', 'items.product.product.media', 'location:id,location_name,location_code']))
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
        $date = now()->format('Ymd');
        $prefix = "PICK-{$date}-";

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

    private function readyToShipStage()
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
