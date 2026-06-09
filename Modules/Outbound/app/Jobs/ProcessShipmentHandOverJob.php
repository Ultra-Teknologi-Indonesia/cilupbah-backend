<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Outbound\Models\Shipment;
use Modules\Sales\Services\SalesOrderService as OrderService;

class ProcessShipmentHandOverJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $shipmentId,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(OrderService $orderService): void
    {
        $shipment = Shipment::with('orders.order')->find($this->shipmentId);

        if (!$shipment || $shipment->status !== Shipment::STATUS_HANDED_OVER) {
            return;
        }

        foreach ($shipment->orders as $shipmentOrder) {
            $order = $shipmentOrder->order;

            if ($order && $order->status === 'packed') {
                $orderService->updateOrder($order, [
                    'status' => 'shipped',
                    'shipping_provider' => $shipment->courier_name,
                    'tracking_number' => $shipmentOrder->tracking_number,
                ]);
            }
        }
    }
}
