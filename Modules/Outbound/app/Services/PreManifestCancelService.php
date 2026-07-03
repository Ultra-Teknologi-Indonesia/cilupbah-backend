<?php

namespace Modules\Outbound\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Sales\Models\SalesOrder as Order;

class PreManifestCancelService
{
    /**
     * Base query: order dibatalkan pasca-packing yang belum di-dismiss
     * dan belum masuk shipment. Ini adalah row yang wajib terlihat
     * di layar Shipping/Pre-Manifest agar tim outbound bisa memisahkan
     * paket fisik sebelum diserahkan ke kurir.
     */
    public function baseQuery(): Builder
    {
        return Order::query()
            ->where('status', 'cancelled')
            ->whereNotNull('handed_to_warehouse_at')
            ->whereNull('cancel_dismissed_at')
            ->whereDoesntHave('shipmentOrders');
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
