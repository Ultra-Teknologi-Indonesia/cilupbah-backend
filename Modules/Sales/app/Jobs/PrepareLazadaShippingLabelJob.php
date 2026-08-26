<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\ChannelLabelUnsupportedException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;

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
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.labels.queue', 'labels'));
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

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'shipping_label', $order->salesorder_no)) {
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
            $packageIds = is_array($order->channel_package_ids) ? $order->channel_package_ids : [];
            if ($packageIds === []) {
                $packageIds = $lazada->resolvePackageIds($shopId, $orderSn);
            }
            if ($packageIds !== []) {
                $order->forceFill([
                    'channel_package_ids' => array_values(array_unique(array_map('strval', $packageIds))),
                ])->saveQuietly();
            }
            $document = $lazada->getPackageDocument($shopId, $packageIds, 'PDF');
        } catch (ChannelLabelUnsupportedException $e) {

            $order->update(['shipping_label_status' => 'self_design_required']);
            Log::info('PrepareLazadaShippingLabelJob: order SOF/DBS, label via Seller Center', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);
            $this->notifyBulkListeners();

            return;
        } catch (\Throwable $e) {
            Log::info('PrepareLazadaShippingLabelJob: dokumen belum siap', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
                'exception' => $e->getMessage(),
            ]);
        }

        if (! empty($document['file']) || ! empty($document['pdf_url'])) {
            $order->update([
                'shipping_label_status' => 'ready',
                'shipping_label_doc_type' => $document['doc_type'] ?? 'PDF',
                'shipping_label_prepared_at' => now(),
                'shipping_label_raw_data' => ['channel' => 'lazada', 'document' => $document],
            ]);

            Log::info('PrepareLazadaShippingLabelJob: shipping document READY', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);

            $this->notifyBulkListeners();

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
                'order_id' => $order->id,
                'order_sn' => $orderSn,
                'next_attempt' => $nextAttempt,
            ]);
            self::dispatch($order->id, $nextAttempt)
                ->onConnection(config('queue.routing.labels.connection', 'redis-long'))
                ->onQueue(config('queue.routing.labels.queue', 'labels'))
                ->delay(now()->addMinutes(5));

            return;
        }

        $order->update(['shipping_label_status' => 'failed']);
        Log::error('PrepareLazadaShippingLabelJob: max attempts tercapai, tandai failed', [
            'order_id' => $order->id,
            'order_sn' => $orderSn,
        ]);

        $this->notifyBulkListeners();
    }

    private function notifyBulkListeners(): void
    {
        try {
            app(BulkShippingLabelService::class)->onOrderLabelReady($this->orderId);
        } catch (\Throwable $e) {
            Log::warning('PrepareLazadaShippingLabelJob: notifyBulkListeners gagal', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PrepareLazadaShippingLabelJob failed permanently', [
            'order_id' => $this->orderId,
            'attempt' => $this->attempt,
            'exception' => $exception->getMessage(),
        ]);

        $order = SalesOrder::find($this->orderId);
        if ($order && $order->shipping_label_status !== 'ready') {
            $order->update(['shipping_label_status' => 'failed']);
        }

        $this->notifyBulkListeners();
    }
}
