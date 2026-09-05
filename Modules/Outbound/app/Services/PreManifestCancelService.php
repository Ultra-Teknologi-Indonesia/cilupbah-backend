<?php

namespace Modules\Outbound\Services;

use App\Support\WarehouseAccess;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Repositories\PreManifestCancelRepository;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Sales\Services\SalesOrderService;

class PreManifestCancelService
{
    public function __construct(
        protected PreManifestCancelRepository $repository,
        protected SalesOrderService $orderService,
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
        return DB::transaction(function () use ($orderId, $actorId): Order {
            $query = Order::whereKey($orderId);
            WarehouseAccess::apply($query, 'location_id');
            $order = $query->lockForUpdate()->firstOrFail();

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

                $this->orderService->logStatusHistory(
                    $order,
                    OrderActivityAction::FIELD_CHANGED,
                    [
                        'entity_no' => $order->salesorder_no,
                        'origin' => 'pre_manifest_cancel',
                        'note' => 'Paket fisik ditandai sudah dipisahkan dari tumpukan.',
                    ],
                    ['email' => $actorId],
                );
            }

            return $order->fresh();
        });
    }

    public function undismiss(string $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            $query = Order::whereKey($orderId);
            WarehouseAccess::apply($query, 'location_id');
            $order = $query->lockForUpdate()->firstOrFail();
            $wasDismissed = ! empty($order->cancel_dismissed_at);

            $order->cancel_dismissed_at = null;
            $order->cancel_dismissed_by = null;
            $order->save();

            if ($wasDismissed) {
                $this->orderService->logStatusHistory(
                    $order,
                    OrderActivityAction::FIELD_CHANGED,
                    [
                        'entity_no' => $order->salesorder_no,
                        'origin' => 'pre_manifest_cancel',
                        'note' => 'Paket dikembalikan ke daftar untuk dipisahkan.',
                    ],
                );
            }

            return $order->fresh();
        });
    }
}
