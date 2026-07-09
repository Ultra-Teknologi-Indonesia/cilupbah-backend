<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Product\Models\ProductChannelMapping;

class ProcessLazadaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    private const MSG_ORDER = 0;
    private const MSG_PRODUCT = 1;
    private const MSG_PRODUCT_ALT = 2;
    private const MSG_SYSTEM = 4;

    public function __construct(
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        LazadaOrderService $orderService,
        ChannelDownloadService $downloadService,
        LazadaAuthService $authService,
    ): void {
        $sellerId = (string) ($this->payload['seller_id'] ?? '');
        $messageType = (int) ($this->payload['message_type'] ?? -1);
        $data = $this->payload['data'] ?? [];

        if ($sellerId === '') {
            Log::warning('Lazada webhook tanpa seller_id — diabaikan.', ['payload' => $this->payload]);

            return;
        }

        if ($this->isTokenExpiryMessage($messageType, $data)) {
            $this->handleTokenExpiry($authService, $sellerId);

            return;
        }

        match ($messageType) {
            self::MSG_ORDER => $this->handleOrderEvent($orderService, $sellerId, $data),
            self::MSG_PRODUCT, self::MSG_PRODUCT_ALT => $this->handleProductEvent($downloadService, $sellerId, $data),
            default => $this->handleUnknown($orderService, $sellerId, $data, $messageType),
        };
    }

    protected function handleUnknown(LazadaOrderService $orderService, string $sellerId, array $data, int $messageType): void
    {
        if ($this->isReverseMessage($data)) {
            $this->handleOrderEvent($orderService, $sellerId, $data);

            $channelOrderId = (string) ($data['trade_order_id'] ?? $data['order_id'] ?? $data['reverse_order_id'] ?? '');
            if ($channelOrderId !== '') {
                try {
                    $salesReturn = app(\Modules\Sales\Services\SalesReturnService::class)->createFromChannel([
                        'source'            => 'lazada',
                        'channel_order_id'  => $channelOrderId,
                        'channel_return_id' => $data['reverse_order_id'] ?? null,
                        'channel_shop_id'   => $sellerId,
                        'reason'            => $data['reverse_status'] ?? $data['order_status'] ?? 'Retur Lazada',
                        'created_by'        => 'system:lazada-webhook',
                    ]);

                    if ($salesReturn) {
                        \Modules\Sales\Jobs\SyncReturnTrackingJob::dispatch((string) $salesReturn->id);
                        \Modules\Sales\Jobs\SyncReturnDetailJob::dispatch((string) $salesReturn->id);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Lazada auto SalesReturn gagal: ' . $e->getMessage(), ['channel_order_id' => $channelOrderId]);
                }
            }

            return;
        }

        Log::info("Lazada webhook message_type {$messageType} belum ditangani — diabaikan.");
    }

    protected function handleOrderEvent(LazadaOrderService $orderService, string $sellerId, array $data): void
    {
        $orderId = (string) ($data['trade_order_id'] ?? $data['order_id'] ?? $data['reverse_order_id'] ?? '');

        if ($orderId === '') {
            Log::warning('Lazada webhook order tanpa id — diabaikan.', ['data' => $data]);

            return;
        }

        $orderService->pullOrderById($sellerId, $orderId);
    }

    protected function handleProductEvent(ChannelDownloadService $downloadService, string $sellerId, array $data): void
    {
        $itemId = (string) ($data['item_id'] ?? '');
        if ($itemId === '') {
            return;
        }

        try {
            $downloadService->downloadProductDebounced('lazada', $sellerId, $itemId);
        } catch (\Throwable $e) {
            Log::warning('Lazada re-sync produk gagal: ' . $e->getMessage(), ['item_id' => $itemId]);
        }

        $shopUuid = DB::table('channel_shops')->where('shop_id', $sellerId)->value('id');
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

    protected function handleTokenExpiry(LazadaAuthService $authService, string $sellerId): void
    {
        $shopUuid = DB::table('channel_shops')
            ->where('shop_id', $sellerId)
            ->whereNull('disconnected_at')
            ->value('id');

        if (! $shopUuid) {
            return;
        }

        try {
            $authService->refreshStoreToken((string) $shopUuid);
            Log::info('Lazada token diperbarui via webhook expiry alert.', ['seller_id' => $sellerId]);
        } catch (\Throwable $e) {
            Log::warning('Lazada refresh token via webhook gagal: ' . $e->getMessage(), ['seller_id' => $sellerId]);
        }
    }

    protected function isTokenExpiryMessage(int $messageType, array $data): bool
    {
        if ($messageType === self::MSG_SYSTEM) {
            return true;
        }

        $marker = strtolower((string) json_encode($data));

        return str_contains($marker, 'token') && str_contains($marker, 'expir');
    }

    protected function isReverseMessage(array $data): bool
    {
        if (isset($data['reverse_order_id']) || isset($data['reverse_status'])) {
            return true;
        }

        $orderStatus = strtolower((string) ($data['order_status'] ?? ''));

        return str_contains($orderStatus, 'return') || str_contains($orderStatus, 'reverse');
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessLazadaWebhook gagal permanen: ' . $e->getMessage(), ['payload' => $this->payload]);
    }
}
