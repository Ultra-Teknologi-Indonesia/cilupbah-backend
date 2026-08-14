<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Services\OutboundFulfillmentService;

class ProcessShipmentPickupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [5, 30, 120, 300, 600];

    public function __construct(
        protected string $shipmentId,
        protected array $orderIds = [],
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(OutboundFulfillmentService $service): void
    {
        $shipment = Shipment::with('orders.order')->find($this->shipmentId);

        if (!$shipment) {
            return;
        }

        $pending = $shipment->orders
            ->filter(fn ($so) => !$so->pickup_status || $so->pickup_status === 'pending')
            ->filter(fn ($so) => empty($this->orderIds) || in_array($so->order_id, $this->orderIds))
            ->filter(fn ($so) => $so->order && in_array(strtolower((string) $so->order->source), ['shopee', 'tiktok', 'lazada']));

        if ($pending->isEmpty()) {
            return;
        }

        ShipmentOrder::whereIn('id', $pending->pluck('id'))->update(['pickup_status' => 'pending']);

        $orderIds = $pending->pluck('order_id')->toArray();

        try {
            $results = $service->readyToShip($orderIds);

            foreach ($results as $result) {
                ShipmentOrder::where('shipment_id', $this->shipmentId)
                    ->where('order_id', $result['order_id'])
                    ->update([
                        'pickup_status' => $result['status'],
                        'pickup_message' => $result['message'] ?? null,
                    ]);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessShipmentPickupJob gagal', [
                'shipment_id' => $this->shipmentId,
                'error' => $e->getMessage(),
            ]);

            ShipmentOrder::where('shipment_id', $this->shipmentId)
                ->whereIn('order_id', $orderIds)
                ->where('pickup_status', 'pending')
                ->update([
                    'pickup_status' => 'failed',
                    'pickup_message' => \App\Support\FriendlyError::generic($e->getMessage(), 'Gagal memproses pickup ke marketplace. Coba lagi.'),
                ]);
        }
    }
}
