<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentTrackingEvent;
use Modules\Outbound\Services\Logistics\LogisticsGateway;
use Modules\Sales\Models\SalesOrder;

class RefreshInstantTrackingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public ?string $shipmentId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(LogisticsGateway $gateway): void
    {
        $shipments = Shipment::query()
            ->when($this->shipmentId, fn ($q) => $q->where('id', $this->shipmentId))
            ->when(! $this->shipmentId, fn ($q) => $q
                ->where('driver_call_status', Shipment::DRIVER_STATUS_CALLED)
                ->whereNull('driver_name')
                ->where('driver_called_at', '>', now()->subHours(6))
            )
            ->with(['orders'])
            ->limit($this->shipmentId ? 1 : 50)
            ->get();

        foreach ($shipments as $shipment) {
            foreach ($shipment->orders as $shipmentOrder) {
                $order = SalesOrder::query()->find($shipmentOrder->order_id);
                if (! $order || ! $order->source) continue;

                try {
                    $adapter = $gateway->for($order->source);
                    $events = $adapter->getTrackingStatus((string) $order->id);
                    foreach ($events as $event) {
                        ShipmentTrackingEvent::create([
                            'shipment_id' => $shipment->id,
                            'source' => strtolower((string) $order->source),
                            'event_type' => $event['event_type'] ?? 'polled_update',
                            'driver_name' => $event['driver_name'] ?? null,
                            'driver_phone' => $event['driver_phone'] ?? null,
                            'driver_vehicle_plate' => $event['driver_vehicle_plate'] ?? null,
                            'raw_payload' => $event['raw'] ?? $event,
                            'occurred_at' => now(),
                            'received_at' => now(),
                        ]);

                        $updates = array_filter([
                            'driver_name' => $event['driver_name'] ?? null,
                            'driver_phone' => $event['driver_phone'] ?? null,
                            'driver_vehicle_plate' => $event['driver_vehicle_plate'] ?? null,
                        ], fn ($v) => $v !== null && $v !== '');
                        if ($updates) {
                            $shipment->update($updates);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('RefreshInstantTrackingJob gagal per-order', [
                        'shipment_id' => $shipment->id,
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
