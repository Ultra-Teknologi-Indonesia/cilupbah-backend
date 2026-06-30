<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Sales\Services\SalesOrderService;

/**
 * Ad-hoc picking: pick langsung dari order tanpa membuat picklist.
 * Order yang sudah 'reserved' & sudah handed_to_warehouse_at langsung di-transisi ke 'picked'
 * lewat SalesOrderService::updateOrder(), yang sudah meng-handle stok (pickStockForOrder)
 * dan validasi transisi status. Progress per-item disimpan di Cache (TTL 1 hari) karena
 * SalesOrderItem belum punya kolom qty_picked.
 */
class OutboundAdHocPickService
{
    public function __construct(
        protected SalesOrderService $orderService,
    ) {}

    public function progressKey(string $orderId): string
    {
        return "ad_hoc_pick:{$orderId}";
    }

    public function loadOrder(string $orderId): Order
    {
        $order = Order::with([
            'items.product:id,sku,product_id',
            'items.product.product:id,product_name',
            'location:id,location_name,location_code',
        ])->find($orderId);

        if (! $order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        return $order;
    }

    public function getProgress(string $orderId): array
    {
        return Cache::get($this->progressKey($orderId), []);
    }

    public function setProgress(string $orderId, array $progress): void
    {
        Cache::put($this->progressKey($orderId), $progress, now()->addDay());
    }

    public function clearProgress(string $orderId): void
    {
        Cache::forget($this->progressKey($orderId));
    }

    /**
     * Selesaikan ad-hoc pick: transisi order 'reserved' -> 'picked'.
     * Stok deduction & validasi transisi dilakukan oleh SalesOrderService::updateOrder().
     */
    public function complete(string $orderId): Order
    {
        $order = $this->loadOrder($orderId);

        if ($order->status === 'picked') {
            throw new \Exception('Order sudah dipick.');
        }

        if ($order->status !== 'reserved') {
            throw new \Exception("Order harus berstatus 'reserved' (saat ini: {$order->status}).");
        }

        if (! $order->handed_to_warehouse_at) {
            throw new \Exception('Order belum diserahkan ke gudang.');
        }

        $updated = DB::transaction(function () use ($order) {
            return $this->orderService->updateOrder($order, ['status' => 'picked']);
        });

        $this->clearProgress($orderId);

        return $updated->load([
            'items.product:id,sku,product_id',
            'items.product.product:id,product_name',
            'location:id,location_name,location_code',
        ]);
    }

    /**
     * Increment scan progress per-SKU. Kalau setelah increment semua item
     * sudah terpenuhi (sum qty_picked == sum qty_in_base), auto-complete.
     *
     * @return array{ completed: bool, order: Order, progress: array<string,int>, matched_item_id: string, qty_picked: int, qty_ordered: int }
     */
    public function scan(string $orderId, string $sku, int $qty = 1): array
    {
        $order = $this->loadOrder($orderId);

        if ($order->status === 'picked') {
            throw new \Exception('Order sudah dipick.');
        }

        if ($order->status !== 'reserved') {
            throw new \Exception("Order harus berstatus 'reserved' (saat ini: {$order->status}).");
        }

        if (! $order->handed_to_warehouse_at) {
            throw new \Exception('Order belum diserahkan ke gudang.');
        }

        $lowerSku = strtolower($sku);
        $item = $order->items->first(function ($it) use ($lowerSku) {
            return strtolower((string) $it->sku) === $lowerSku;
        });

        if (! $item) {
            throw new \Exception("SKU/Barcode '{$sku}' tidak ditemukan pada order ini.");
        }

        $progress = $this->getProgress($orderId);
        $current = (int) ($progress[$item->id] ?? 0);
        $ordered = (int) $item->qty_in_base;

        if ($current >= $ordered) {
            throw new \Exception("Qty untuk SKU '{$sku}' sudah terpenuhi ({$current}/{$ordered}).");
        }

        $next = min($ordered, $current + max(1, $qty));
        $progress[$item->id] = $next;
        $this->setProgress($orderId, $progress);

        $allDone = $order->items->every(function ($it) use ($progress) {
            return (int) ($progress[$it->id] ?? 0) >= (int) $it->qty_in_base;
        });

        $completed = false;
        $orderOut = $order;
        if ($allDone) {
            $orderOut = $this->complete($orderId);
            $completed = true;
        }

        return [
            'completed' => $completed,
            'order' => $orderOut,
            'progress' => $progress,
            'matched_item_id' => $item->id,
            'qty_picked' => $next,
            'qty_ordered' => $ordered,
        ];
    }
}
