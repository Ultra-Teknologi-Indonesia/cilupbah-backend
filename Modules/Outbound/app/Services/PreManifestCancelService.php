<?php

namespace Modules\Outbound\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Sales\Models\SalesOrder as Order;

class PreManifestCancelService
{

    public function baseQuery(): Builder
    {
        return Order::query()
            ->where('status', 'cancelled')
            ->whereNotNull('handed_to_warehouse_at')
            ->whereNull('cancel_dismissed_at')
            ->whereDoesntHave('shipmentOrders');
    }

    public function list(array $filters = [], int $perPage = 20)
    {
        $query = $this->baseQuery()
            ->with(['location:id,location_name,location_code'])
            ->latest('cancel_accepted_at');

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }
        if (! empty($filters['q'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('salesorder_no', 'ilike', "%{$filters['q']}%")
                  ->orWhere('channel_order_no', 'ilike', "%{$filters['q']}%")
                  ->orWhere('customer_name', 'ilike', "%{$filters['q']}%")
                  ->orWhere('tracking_number', 'ilike', "%{$filters['q']}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function dismiss(string $orderId, string $actorId): Order
    {
        $order = Order::findOrFail($orderId);

        if ($order->status !== 'cancelled') {
            throw new \Exception("Order tidak dalam status 'cancelled', tidak bisa di-dismiss.");
        }

        if (empty($order->handed_to_warehouse_at)) {
            throw new \Exception('Order belum sampai tahap pasca-packing, tidak relevan untuk di-dismiss.');
        }

        if (empty($order->cancel_dismissed_at)) {
            $order->cancel_dismissed_at = now();
            $order->cancel_dismissed_by = $actorId;
            $order->save();
        }

        return $order->fresh();
    }

    public function undismiss(string $orderId): Order
    {
        $order = Order::findOrFail($orderId);

        $order->cancel_dismissed_at = null;
        $order->cancel_dismissed_by = null;
        $order->save();

        return $order->fresh();
    }
}
