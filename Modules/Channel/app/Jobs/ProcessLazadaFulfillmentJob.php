<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;

class ProcessLazadaFulfillmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public string $shopId,
        public string $orderId,
        public string $shippingProviderId,
        public string $deliveryType = 'dropship',
        public ?string $trackingNumber = null,
        public ?string $packageId = null,
    ) {
        $this->onQueue(config('queue.names.channel_fulfillment'));
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lazada_fulfillment:{$this->shopId}:{$this->orderId}"))
                ->releaseAfter(60)
                ->expireAfter(180),
        ];
    }

    public function uniqueId(): string
    {
        return "{$this->shopId}:{$this->orderId}";
    }

    public function handle(LazadaOrderService $orderService): void
    {
        if (ChannelFulfillmentGuard::blocks($this->shopId, 'lazada_fulfillment', $this->orderId)) {
            return;
        }

        $order = SalesOrder::query()
            ->where('source', 'lazada')
            ->where('channel_shop_id', $this->shopId)
            ->where('channel_order_no', $this->orderId)
            ->first();

        if ($order === null) {
            Log::warning('Lazada fulfillment skipped because the local order is unavailable.', [
                'shop_id' => $this->shopId,
                'channel_order_no' => $this->orderId,
            ]);

            return;
        }

        if (ChannelOrderSideEffectGuard::active($order->id, 'lazada_fulfillment') === null) {
            return;
        }

        $statuses = $orderService->itemStatuses($this->shopId, $this->orderId);

        if (array_intersect($statuses, ['pending', 'repacked'])) {
            if (ChannelOrderSideEffectGuard::active($order->id, 'lazada_fulfillment_pack') === null) {
                return;
            }

            $packResult = $orderService->fulfillPack($this->shopId, $this->orderId, $this->shippingProviderId, $this->deliveryType);
            $packData = $packResult['pack'] ?? [];
            if (! empty($packData['pack_order_list'])) {
                foreach ($packData['pack_order_list'] as $pol) {
                    foreach ($pol['order_item_list'] ?? [] as $oil) {
                        $this->packageId = $this->packageId ?: ($oil['package_id'] ?? null);
                        $this->trackingNumber = $this->trackingNumber ?: ($oil['tracking_number'] ?? null);
                    }
                }
            }
        } else {
            Log::info("Lazada fulfillment: order {$this->orderId} sudah melewati tahap pack, dilewati.");
        }

        if (ChannelOrderSideEffectGuard::active($order->id, 'lazada_fulfillment_print_awb') === null) {
            return;
        }

        $orderService->printAwb($this->shopId, $this->orderId);

        $statusesAfterPack = $orderService->itemStatuses($this->shopId, $this->orderId);

        if (array_intersect($statusesAfterPack, ['packed'])) {
            if (ChannelOrderSideEffectGuard::active($order->id, 'lazada_fulfillment_ready_to_ship') === null) {
                return;
            }

            $orderService->readyToShip(
                $this->shopId,
                $this->orderId,
                $this->trackingNumber,
                $this->packageId,
                $this->deliveryType
            );
        } else {
            Log::info("Lazada fulfillment: order {$this->orderId} sudah melewati tahap ready-to-ship, dilewati.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Lazada fulfillment GAGAL PERMANEN — perlu RTS manual untuk order {$this->orderId} (toko {$this->shopId}): ".$exception->getMessage());

        try {
            $order = SalesOrder::query()
                ->where('source', 'lazada')
                ->where('channel_order_no', $this->orderId)
                ->first();

            if ($order) {
                ShipmentOrder::query()
                    ->where('order_id', $order->id)
                    ->update([
                        'pickup_status' => 'failed',
                        'pickup_message' => 'Lazada fulfillment gagal: '.mb_substr($exception->getMessage(), 0, 250),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ProcessLazadaFulfillmentJob: gagal update status order on failure: '.$e->getMessage());
        }
    }
}
