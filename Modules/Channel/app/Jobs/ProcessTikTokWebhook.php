<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\RateLimited;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WebhookProductHandler;

class ProcessTikTokWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function middleware(): array
    {
        return [new RateLimited('tiktok_api')];
    }

    public function handle(TikTokOrderService $orderService, WebhookProductHandler $productHandler): void
    {
        $type = $this->payload['type'] ?? null;
        $shopId = $this->payload['shop_id'] ?? null;

        if (!$type || !$shopId) {
            Log::warning('TikTok Webhook Job missing type or shop_id', $this->payload);
            return;
        }

        $idempotencyKey = "tiktok_webhook_processed:{$shopId}:" . md5(json_encode($this->payload));

        if (! Cache::add($idempotencyKey, true, 600)) {
            Log::info("TikTok Webhook already processed (Idempotency Key: {$idempotencyKey})");
            return;
        }

        try {
            switch ($type) {
                case 1:
                case '1':
                    $orderId = $this->payload['data']['order_id'] ?? null;
                    if ($orderId) {
                        $orderService->pullOrderById($shopId, $orderId);
                    }
                    break;
                case 2:
                case '2':
                    $productHandler->handleProductStatusChange($this->payload['data'] ?? [], $shopId);
                    break;
                case 3:
                case '3':
                    $productHandler->handleProductUpdate($this->payload['data'] ?? [], $shopId);
                    break;
                case 4:
                case '4':
                    $orderId = $this->payload['data']['order_id'] ?? null;
                    if ($orderId) {
                        $orderService->pullOrderById($shopId, $orderId);
                        Log::info("TikTok Webhook type 4 (cancel): order {$orderId} resynced.", ['shop_id' => $shopId]);
                    }
                    break;
                case 5:
                case '5':
                    $orderId = $this->payload['data']['order_id'] ?? null;
                    if ($orderId) {
                        $orderService->pullOrderById($shopId, $orderId);
                        Log::info("TikTok Webhook type 5 (return/refund): order {$orderId} resynced.", ['shop_id' => $shopId]);

                        try {
                            app(\Modules\Sales\Services\SalesReturnService::class)->createFromChannel([
                                'source'            => 'tiktok',
                                'channel_order_id'  => (string) $orderId,
                                'channel_return_id' => $this->payload['data']['return_id']
                                    ?? $this->payload['data']['reverse_order_id']
                                    ?? null,
                                'channel_shop_id'   => (string) $shopId,
                                'reason'            => 'Retur TikTok',
                                'created_by'        => 'system:tiktok-webhook',
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('TikTok auto SalesReturn gagal: ' . $e->getMessage(), ['order_id' => $orderId]);
                        }
                    }
                    break;
                default:
                    Log::info('Unhandled TikTok Webhook Type in Job: ' . $type);
                    break;
            }
        } catch (\Exception $e) {
            Cache::forget($idempotencyKey);
            throw $e;
        }
    }
}
