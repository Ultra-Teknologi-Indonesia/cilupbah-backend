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

class ProcessLazadaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public array $payload,
    ) {

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

    protected function handleOrderEvent(LazadaOrderService $orderService, string $sellerId, array $data): void
    {
        $orderId = (string) ($data['trade_order_id'] ?? $data['order_id'] ?? '');

        if ($orderId === '') {
            Log::warning('Lazada webhook order tanpa trade_order_id — diabaikan.', ['data' => $data]);

            return;
        }

        $orderService->pullOrderById($sellerId, $orderId);
    }

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
            $mapping->markApproved();
        } elseif (in_array($status, ['pending', 'reviewing', 'pending_qc'], true)) {
            $mapping->markInReview();
        } elseif (in_array($status, ['rejected', 'suspended', 'deleted', 'inactive'], true)) {
            $reason = $data['reasons'] ?? $data['reason'] ?? $status;
            $mapping->markRejected('Lazada QC: ' . (is_array($reason) ? json_encode($reason) : $reason));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessLazadaWebhook gagal permanen: ' . $e->getMessage(), ['payload' => $this->payload]);
    }
}
