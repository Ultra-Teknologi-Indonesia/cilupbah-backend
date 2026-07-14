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
                        $this->recordTikTokTrackingEvent((string) $orderId, $this->payload['data'] ?? []);
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
                            $salesReturn = app(\Modules\Sales\Services\SalesReturnService::class)->createFromChannel([
                                'source'            => 'tiktok',
                                'channel_order_id'  => (string) $orderId,
                                'channel_return_id' => $this->payload['data']['return_id']
                                    ?? $this->payload['data']['reverse_order_id']
                                    ?? null,
                                'channel_shop_id'   => (string) $shopId,
                                'reason'            => $this->payload['data']['return_reason']
                                    ?? $this->payload['data']['reason']
                                    ?? 'Retur TikTok',
                                'created_by'        => 'system:tiktok-webhook',
                            ]);

                            if ($salesReturn) {
                                \Modules\Sales\Jobs\SyncReturnTrackingJob::dispatch((string) $salesReturn->id);
                                \Modules\Sales\Jobs\SyncReturnDetailJob::dispatch((string) $salesReturn->id);
                            }
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

    protected function recordTikTokTrackingEvent(string $orderId, array $data): void
    {
        $status = strtoupper((string) ($data['order_status'] ?? $data['status'] ?? ''));
        $isDriverEvent = in_array($status, [
            'AWAITING_COLLECTION',
            'IN_TRANSIT',
            'DELIVERED',
        ], true);
        if (! $isDriverEvent) return;

        $order = \Modules\Sales\Models\SalesOrder::query()
            ->where('source', 'tiktok')
            ->where('channel_order_no', $orderId)
            ->first();
        if (! $order) return;

        $shipment = \Modules\Outbound\Models\Shipment::query()
            ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
            ->latest('id')
            ->first();
        if (! $shipment) return;

        $eventType = match ($status) {
            'AWAITING_COLLECTION' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DRIVER_ASSIGNED,
            'IN_TRANSIT' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_IN_TRANSIT,
            'DELIVERED' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DELIVERED,
            default => 'tiktok_' . strtolower($status),
        };

        \Modules\Outbound\Models\ShipmentTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'source' => 'tiktok',
            'event_type' => $eventType,
            'driver_name' => null,
            'driver_phone' => null,
            'driver_vehicle_plate' => null,
            'raw_payload' => $data,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        \Modules\Outbound\Jobs\RefreshInstantTrackingJob::dispatch($shipment->id);
    }
}
