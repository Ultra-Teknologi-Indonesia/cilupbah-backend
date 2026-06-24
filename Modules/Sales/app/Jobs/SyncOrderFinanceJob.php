<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

/**
 * Tarik data keuangan final (escrow/finance) sebuah order dari channel lalu simpan
 * lewat SalesOrderService::updateOrderFinance().
 *
 * Idempoten: bila order sudah is_settled, job langsung selesai tanpa memanggil API.
 * Tidak menyentuh status/stok — murni jalur keuangan.
 */
class SyncOrderFinanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly string $orderId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(SalesOrderService $orderService): void
    {
        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return;
        }

        // Sudah final → tidak perlu memanggil API lagi (kecuali dipaksa, mis. setelah return).
        if ($order->is_settled && ! $this->force) {
            return;
        }

        $finance = match ($order->source) {
            'shopee' => $this->fetchShopee($order),
            'tiktok' => $this->fetchTikTok($order),
            default  => null,
        };

        if ($finance === null) {
            return;
        }

        // Jangan tandai settled bila channel belum mengirim angka final — biarkan
        // nightly sweep mencoba lagi nanti. Tetap simpan estimasi yang ada.
        $orderService->updateOrderFinance($order->id, $finance);

        Log::info('SyncOrderFinanceJob: finance updated', [
            'order_id'      => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source'        => $order->source,
            'is_settled'    => $finance['is_settled'] ?? false,
        ]);
    }

    private function fetchShopee(SalesOrder $order): ?array
    {
        $service = app(\Modules\Channel\Services\ShopeeOrderService::class);
        $mapper = app(\Modules\Channel\Services\ShopeeEscrowMapper::class);

        $escrow = $service->getEscrowDetail($order->channel_shop_id, $order->channel_order_no);

        if (empty($escrow)) {
            return null;
        }

        return $mapper->map($escrow);
    }

    private function fetchTikTok(SalesOrder $order): ?array
    {
        $service = app(\Modules\Channel\Services\TikTokOrderService::class);
        $mapper = app(\Modules\Channel\Services\TikTokStatementMapper::class);

        $statement = $service->getOrderStatement($order->channel_shop_id, $order->channel_order_no);

        if (empty($statement)) {
            return null;
        }

        return $mapper->map($statement);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncOrderFinanceJob failed permanently', [
            'order_id'  => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
