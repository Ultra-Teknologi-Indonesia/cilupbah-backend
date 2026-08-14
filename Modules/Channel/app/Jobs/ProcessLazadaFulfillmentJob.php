<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;

class ProcessLazadaFulfillmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $shopId,
        public string $orderId,
        public string $shippingProviderId,
        public string $deliveryType = 'dropship',
        public ?string $trackingNumber = null,
        public ?string $packageId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lazada_fulfillment:{$this->shopId}:{$this->orderId}"))->releaseAfter(60),
        ];
    }

    public function handle(LazadaOrderService $orderService): void
    {
        if (ChannelFulfillmentGuard::blocks($this->shopId, 'lazada_fulfillment', $this->orderId)) {
            return;
        }

        $statuses = $orderService->itemStatuses($this->shopId, $this->orderId);

        if (array_intersect($statuses, ['pending', 'repacked'])) {
            $orderService->fulfillPack($this->shopId, $this->orderId, $this->shippingProviderId, $this->deliveryType);
        } else {
            Log::info("Lazada fulfillment: order {$this->orderId} sudah melewati tahap pack, dilewati.");
        }

        $orderService->printAwb($this->shopId, $this->orderId);

        $statusesAfterPack = $orderService->itemStatuses($this->shopId, $this->orderId);

        if (array_intersect($statusesAfterPack, ['packed'])) {
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
        Log::error("Lazada fulfillment GAGAL PERMANEN — perlu RTS manual untuk order {$this->orderId} (toko {$this->shopId}): " . $exception->getMessage());

        try {
            $order = \Modules\Sales\Models\SalesOrder::query()
                ->where('source', 'lazada')
                ->where('channel_order_no', $this->orderId)
                ->first();

            if ($order) {
                \Modules\Outbound\Models\ShipmentOrder::query()
                    ->where('order_id', $order->id)
                    ->update([
                        'pickup_status' => 'failed',
                        'pickup_message' => 'Lazada fulfillment gagal: ' . mb_substr($exception->getMessage(), 0, 250),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ProcessLazadaFulfillmentJob: gagal update status order on failure: ' . $e->getMessage());
        }
    }
}
