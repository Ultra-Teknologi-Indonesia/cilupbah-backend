<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;

class RequestChannelAwbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $orderId,
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(OutboundFulfillmentService $fulfillment): void
    {
        $order = SalesOrder::find($this->orderId);

        if (! $order) {
            return;
        }

        $source = strtolower((string) $order->source);

        if (! in_array($source, ['shopee', 'tiktok', 'lazada'], true)) {
            return;
        }

        if (! empty($order->tracking_number)) {
            return;
        }

        try {
            $results = $fulfillment->readyToShip([$order->id]);
            $result = $results[0] ?? null;

            Log::info('RequestChannelAwbJob: readyToShip dispatched', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source'        => $source,
                'status'        => $result['status'] ?? 'unknown',
                'message'       => $result['message'] ?? null,
            ]);

            if (($result['status'] ?? null) === 'failed') {
                throw new \RuntimeException($result['message'] ?? 'readyToShip gagal.');
            }
        } catch (\Throwable $e) {
            Log::error('RequestChannelAwbJob: error saat request AWB', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source'        => $source,
                'exception'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RequestChannelAwbJob failed permanently', [
            'order_id'  => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
