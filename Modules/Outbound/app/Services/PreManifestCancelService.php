<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\PreManifestCancelRepository;
use Modules\Sales\Models\SalesOrder as Order;

class PreManifestCancelService
{
    public function __construct(
        protected PreManifestCancelRepository $repository,
    ) {}

    public function list(array $filters = [], int $perPage = 10)
    {
        return $this->repository->paginateList($filters, $perPage);
    }

    public function count(): int
    {
        return $this->repository->count();
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
