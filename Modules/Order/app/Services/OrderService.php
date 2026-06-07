<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Exceptions\CannotDeleteActiveOrderException;
use Modules\Order\Exceptions\DuplicateOrderException;
use Modules\Order\Exceptions\InsufficientStockException;
use Modules\Order\Exceptions\InvalidStatusTransitionException;
use Modules\Order\Jobs\CancelChannelOrderJob;
use Modules\Order\Jobs\SyncStockJob;
use Modules\Order\Models\Order;
use Modules\Order\Repositories\OrderRepository;

class OrderService
{
    private const ALLOWED_TRANSITIONS = [
        'pending'   => ['reserved', 'cancelled'],
        'reserved'  => ['picked', 'cancelled'],
        'picked'    => ['packed', 'cancelled'],
        'packed'    => ['shipped', 'cancelled'],
        'shipped'   => [],
        'cancelled' => [],
    ];

    private const IDEMPOTENCY_TTL = 172800; // 48 hours

    public function __construct(
        protected OrderRepository $orderRepository,
        protected StockService $stockService,
    ) {}

    public function getPaginatedOrders()
    {
        return $this->orderRepository->getPaginatedOrders();
    }

    public function getOrderById($id)
    {
        return $this->orderRepository->getOrderById($id);
    }

    public function createOrder(array $validated): Order
    {
        $marketplace = $validated['source'] ?? 'manual';
        $marketplaceOrderId = $validated['salesorder_no'];
        $idempotencyKey = "order:done:{$marketplace}:{$marketplaceOrderId}";

        if (Cache::has($idempotencyKey)) {
            throw new DuplicateOrderException($marketplace, $marketplaceOrderId);
        }

        $order = DB::transaction(function () use ($validated) {
            $order = Order::create(array_merge($validated, ['status' => 'pending']));

            if (! empty($validated['items'])) {
                $this->orderRepository->syncOrderItems($order->id, $validated['items']);
            }

            $order->load('items');
            $this->reserveStockForOrder($order);

            $order->status = 'reserved';
            $order->save();

            return $order;
        });

        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

        SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));

        return $order->fresh('items');
    }

    public function updateOrder(Order $order, array $validated): Order
    {
        $stockMutated = false;

        if (isset($validated['status']) && $validated['status'] !== $order->status) {
            $newStatus = $validated['status'];
            $this->validateTransition($order->status, $newStatus);

            DB::transaction(function () use ($order, $newStatus) {
                $this->applyStockTransition($order, $newStatus);
                $order->status = $newStatus;

                if ($newStatus === 'cancelled') {
                    $order->is_canceled = true;
                }

                $order->save();
            });

            $stockMutated = true;

            if ($newStatus === 'cancelled' && $order->source) {
                $cancelReason = $validated['cancel_reason'] ?? 'seller_cancel_reason_out_of_stock';
                CancelChannelOrderJob::dispatch($order->id, $cancelReason)
                    ->onQueue(config('queue.names.orders'));
            }
        }

        $allowedFields = ['customer_name', 'shipping_address', 'seller_note'];
        $fieldsToUpdate = array_intersect_key($validated, array_flip($allowedFields));

        if (! empty($fieldsToUpdate)) {
            $order->update($fieldsToUpdate);
        }

        if ($stockMutated) {
            SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
        }

        return $order->fresh('items');
    }

    public function deleteOrder(Order $order): void
    {
        if (! in_array($order->status, ['pending', 'cancelled'])) {
            if ($order->status === 'reserved') {
                DB::transaction(function () use ($order) {
                    $this->releaseStockForOrder($order);
                    $order->delete();
                });
            } else {
                throw new CannotDeleteActiveOrderException($order->status);
            }
        } else {
            $order->delete();
        }

        $marketplace = $order->source ?? 'manual';
        $idempotencyKey = "order:done:{$marketplace}:{$order->salesorder_no}";
        Cache::forget($idempotencyKey);

        SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
    }

    public function upsertFromChannel(array $orderData): ?int
    {
        $channelStatus = $orderData['channel_status'] ?? 'UNKNOWN';
        $mappedStatus = $this->mapChannelStatusToInternal($channelStatus);

        try {
            DB::beginTransaction();

            $existing = DB::table('orders')
                ->where('salesorder_no', $orderData['salesorder_no'])
                ->lockForUpdate()
                ->first();

            $previousStatus = $existing?->status;
            $finalStatus = $this->resolveInternalStatus($previousStatus, $mappedStatus);
            $orderData['status'] = $finalStatus;

            $order = $this->orderRepository->upsertOrderBySalesOrderNo($orderData['salesorder_no'], $orderData);

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $this->orderRepository->syncOrderItems($order->id, $orderData['items']);
            }

            $order->load('items');

            $stockMutated = $this->applyChannelStockTransition($order, $previousStatus, $finalStatus);

            DB::commit();

            if ($stockMutated) {
                SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
            }

            return $order->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to upsert order: " . $e->getMessage());
            throw $e;
        }
    }

    private function mapChannelStatusToInternal(string $channelStatus): string
    {
        return match ($channelStatus) {
            'UNPAID', 'ON_HOLD'                => 'pending',
            'AWAITING_SHIPMENT'                => 'reserved',
            'AWAITING_COLLECTION', 'IN_TRANSIT' => 'packed',
            'DELIVERED', 'COMPLETED'           => 'shipped',
            'CANCELLED'                        => 'cancelled',
            default                            => 'pending',
        };
    }

    private const STATUS_RANK = [
        'pending'   => 0,
        'reserved'  => 1,
        'picked'    => 2,
        'packed'    => 3,
        'shipped'   => 4,
    ];

    private function resolveInternalStatus(?string $currentStatus, string $newStatus): string
    {
        if (! $currentStatus) {
            return $newStatus;
        }

        if ($currentStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($newStatus === 'cancelled') {
            return 'cancelled';
        }

        $currentRank = self::STATUS_RANK[$currentStatus] ?? -1;
        $newRank = self::STATUS_RANK[$newStatus] ?? -1;

        return $newRank > $currentRank ? $newStatus : $currentStatus;
    }

    private function applyChannelStockTransition(Order $order, ?string $previousStatus, string $finalStatus): bool
    {
        if (in_array($previousStatus, [null, 'pending']) && $finalStatus === 'reserved') {
            $this->reserveStockForOrder($order);
            return true;
        }

        if (in_array($previousStatus, ['reserved', 'picked', 'packed']) && $finalStatus === 'cancelled') {
            $order->status = $previousStatus;
            $this->releaseStockForOrder($order);
            $order->status = $finalStatus;
            return true;
        }

        return false;
    }

    private function validateTransition(string $from, string $to): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed)) {
            throw new InvalidStatusTransitionException($from, $to);
        }
    }

    private function applyStockTransition(Order $order, string $newStatus): void
    {
        $order->load('items');

        match ($newStatus) {
            'reserved'  => $this->reserveStockForOrder($order),
            'picked'    => $this->pickStockForOrder($order),
            'shipped'   => $this->shipStockForOrder($order),
            'cancelled' => $this->releaseStockForOrder($order),
            default     => null,
        };
    }

    private function reserveStockForOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->reserve(
                $item->sku ?? "item:{$item->item_id}",
                $item->item_id,
                $locationId,
                $item->qty_in_base,
                $order->salesorder_no,
            );
        }
    }

    private function pickStockForOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->pick(
                $item->sku ?? "item:{$item->item_id}",
                $item->item_id,
                $locationId,
                $item->qty_in_base,
                $order->salesorder_no,
            );
        }
    }

    private function shipStockForOrder(Order $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->ship(
                $item->sku ?? "item:{$item->item_id}",
                $item->item_id,
                $locationId,
                $item->qty_in_base,
                $order->salesorder_no,
            );
        }
    }

    private function releaseStockForOrder(Order $order): void
    {
        if (! in_array($order->status, ['reserved', 'picked', 'packed'])) {
            return;
        }

        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->cancel(
                $item->sku ?? "item:{$item->item_id}",
                $item->item_id,
                $locationId,
                $item->qty_in_base,
                $order->salesorder_no,
            );
        }
    }

    private function resolveLocationId(Order $order): string
    {
        if ($order->channel_shop_id) {
            $mapping = DB::table('channel_warehouses')
                ->where('channel_shop_id', $order->channel_shop_id)
                ->first();

            if ($mapping) {
                return $mapping->location_id;
            }
        }

        $defaultLocation = DB::table('locations')->first();

        return $defaultLocation->id;
    }
}
