<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Sales\Models\SalesOrder;

class PrepareLazadaShippingLabelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    private const MAX_GLOBAL_ATTEMPTS = 3;

    public function __construct(
        public readonly string $orderId,
        public readonly int $attempt = 0,
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(LazadaOrderService $lazada): void
    {
        $order = SalesOrder::find($this->orderId);
        if (! $order) {
            return;
        }

        if (strtolower((string) $order->source) !== 'lazada') {
            return;
        }

        if ($order->shipping_label_status === 'ready') {
            return;
        }

        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;

        if ($shopId === '' || $orderSn === '') {
            Log::warning('PrepareLazadaShippingLabelJob: channel_shop_id / channel_order_no kosong', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $order->update(['shipping_label_status' => 'preparing']);

        $document = [];
        try {
            $document = $lazada->getDocument($shopId, $orderSn, 'shippingLabel');
        } catch (\Throwable $e) {

            Log::info('PrepareLazadaShippingLabelJob: dokumen belum siap', [
                'order_id'  => $order->id,
                'order_sn'  => $orderSn,
                'exception' => $e->getMessage(),
            ]);
        }

        $file = $document['file'] ?? $document['pdf'] ?? null;

        if (! empty($file)) {
            $order->update([
                'shipping_label_status'      => 'ready',
                'shipping_label_doc_type'    => 'PDF',
                'shipping_label_prepared_at' => now(),
                'shipping_label_raw_data'    => ['channel' => 'lazada', 'document' => $document],
            ]);

            Log::info('PrepareLazadaShippingLabelJob: shipping document READY', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);

            return;
        }

        $this->retryOrFail($order, $orderSn);
    }

    private function retryOrFail(SalesOrder $order, string $orderSn): void
    {
        $order->update(['shipping_label_status' => 'not_ready']);

        $nextAttempt = $this->attempt + 1;
        if ($nextAttempt < self::MAX_GLOBAL_ATTEMPTS) {
            Log::warning('PrepareLazadaShippingLabelJob: label belum siap, retry', [
                'order_id'     => $order->id,
                'order_sn'     => $orderSn,
                'next_attempt' => $nextAttempt,
            ]);
            self::dispatch($order->id, $nextAttempt)
                ->onQueue(config('queue.names.channel_sync'))
                ->delay(now()->addMinutes(5));

            return;
        }

        $order->update(['shipping_label_status' => 'failed']);
        Log::error('PrepareLazadaShippingLabelJob: max attempts tercapai, tandai failed', [
            'order_id' => $order->id,
            'order_sn' => $orderSn,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PrepareLazadaShippingLabelJob failed permanently', [
            'order_id'  => $this->orderId,
            'attempt'   => $this->attempt,
            'exception' => $exception->getMessage(),
        ]);

        $order = SalesOrder::find($this->orderId);
        if ($order && $order->shipping_label_status !== 'ready') {
            $order->update(['shipping_label_status' => 'failed']);
        }
    }
}
