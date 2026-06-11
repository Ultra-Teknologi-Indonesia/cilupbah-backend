<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Product\Models\ProductChannelMapping;

/**
 * Proses event push Lazada secara asinkron (controller hanya verifikasi + antre).
 * message_type: 0 = order status changed, 1 = product QC/audit, 2 = product item changed.
 * Event tak dikenal di-log lalu diabaikan — tidak pernah melempar ke retry-loop.
 */
class ProcessLazadaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public array $payload,
    ) {
        // Queue 'default' sudah dilayani supervisor-default (pola tiktok-webhooks).
        $this->onQueue('default');
    }

    public function handle(LazadaOrderService $orderService): void
    {
        $sellerId = (string) ($this->payload['seller_id'] ?? '');
        $messageType = (int) ($this->payload['message_type'] ?? -1);
        $data = $this->payload['data'] ?? [];

        if ($sellerId === '') {
            Log::warning('Lazada webhook tanpa seller_id — diabaikan.', ['payload' => $this->payload]);

            return;
        }

        match ($messageType) {
            0 => $this->handleOrderEvent($orderService, $sellerId, $data),
            1, 2 => $this->handleProductEvent($sellerId, $data),
            default => Log::info("Lazada webhook message_type {$messageType} belum ditangani — diabaikan."),
        };
    }

    /** Order status berubah → tarik ulang order tunggal (upsert + transisi stok resmi). */
    protected function handleOrderEvent(LazadaOrderService $orderService, string $sellerId, array $data): void
    {
        $orderId = (string) ($data['trade_order_id'] ?? $data['order_id'] ?? '');

        if ($orderId === '') {
            Log::warning('Lazada webhook order tanpa trade_order_id — diabaikan.', ['data' => $data]);

            return;
        }

        $orderService->pullOrderById($sellerId, $orderId);
    }

    /** Hasil QC / perubahan item produk → perbarui sync_status mapping. */
    protected function handleProductEvent(string $sellerId, array $data): void
    {
        $itemId = (string) ($data['item_id'] ?? '');
        if ($itemId === '') {
            return;
        }

        $shopUuid = \Illuminate\Support\Facades\DB::table('channel_shops')
            ->where('shop_id', $sellerId)
            ->value('id');

        if (! $shopUuid) {
            return;
        }

        $mapping = ProductChannelMapping::where('channel_shop_id', $shopUuid)
            ->where('external_product_id', $itemId)
            ->first();

        if (! $mapping) {
            return;
        }

        $status = strtolower((string) ($data['qc_status'] ?? $data['status'] ?? ''));

        if (in_array($status, ['approved', 'active'], true)) {
            $mapping->update(['sync_status' => 'synced', 'error_message' => null]);
        } elseif (in_array($status, ['rejected', 'suspended', 'deleted', 'inactive'], true)) {
            $mapping->update([
                'sync_status' => 'failed',
                'error_message' => 'Lazada QC: ' . ($data['reasons'] ?? $data['reason'] ?? $status),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessLazadaWebhook gagal permanen: ' . $e->getMessage(), ['payload' => $this->payload]);
    }
}
