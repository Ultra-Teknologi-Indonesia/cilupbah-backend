<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;

class PrepareShopeeShippingLabelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    private const MAX_GLOBAL_ATTEMPTS = 6;

    private const RETRY_DELAYS = [5, 10, 20, 30, 60, 120];

    public function __construct(
        public readonly string $orderId,
        public readonly int $attempt = 0,
    ) {
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.labels.queue', 'labels'));
    }

    public function handle(ShopeeOrderService $shopee): void
    {
        $order = SalesOrder::find($this->orderId);
        if (! $order) {
            return;
        }

        if (strtolower((string) $order->source) !== 'shopee') {
            return;
        }

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'shipping_label', $order->salesorder_no)) {
            return;
        }

        if (empty($order->tracking_number)) {
            Log::info('PrepareShopeeShippingLabelJob: tracking_number kosong, skip', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
            ]);

            return;
        }

        if (in_array($order->shipping_label_status, ['ready', 'self_design_required'], true)) {
            return;
        }

        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;

        if ($shopId === '' || $orderSn === '') {
            Log::warning('PrepareShopeeShippingLabelJob: channel_shop_id / channel_order_no kosong', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $order->update(['shipping_label_status' => 'preparing']);

        if ($this->attempt === 0) {
            try {
                $shop = (object) ['shop_id' => $shopId];
                $selfDesign = $shopee->checkAllowSelfDesignAwb($shop, $orderSn);
            } catch (\Throwable $e) {
                $selfDesign = false;
                Log::warning('PrepareShopeeShippingLabelJob: checkAllowSelfDesignAwb gagal, fallback ke flow normal', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                    'exception' => $e->getMessage(),
                ]);
            }

            if ($selfDesign) {
                $order->update([
                    'shipping_label_status' => 'self_design_required',
                    'shipping_label_doc_type' => null,
                    'shipping_label_prepared_at' => now(),
                ]);

                Log::info('PrepareShopeeShippingLabelJob: channel mengharuskan self-design AWB, tidak ada PDF dari sistem', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                ]);

                $this->notifyBulkListeners();

                return;
            }
        }

        try {
            $docType = $shopee->resolveSupportedDocType($shopId, $orderSn, 'THERMAL_AIR_WAYBILL');
        } catch (\Throwable $e) {
            $docType = 'THERMAL_AIR_WAYBILL';
            Log::warning('PrepareShopeeShippingLabelJob: resolveSupportedDocType gagal, pakai default', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        if ($this->attempt === 0) {
            $create = $shopee->createShippingDocument(
                $shopId,
                $orderSn,
                $docType,
                $order->tracking_number,
                $order->package_number ?? null
            );
            if (! empty($create['error'])) {
                $failDetail = $create['response']['result_list'][0]['fail_message']
                    ?? $create['response']['result_list'][0]['fail_error']
                    ?? ($create['message'] ?? null);

                $errCode = (string) $create['error'];
                $recoverable = str_contains($errCode, 'duplicate') || str_contains($errCode, 'already');

                if (! $recoverable) {
                    $order->update(['shipping_label_status' => 'failed']);
                    Log::error('PrepareShopeeShippingLabelJob: createShippingDocument gagal', [
                        'order_id' => $order->id,
                        'order_sn' => $orderSn,
                        'doc_type' => $docType,
                        'error' => $errCode,
                        'message' => $failDetail,
                    ]);
                    $this->notifyBulkListeners();
                    throw new \RuntimeException("Shopee createShippingDocument gagal: {$errCode} {$failDetail}");
                }

                Log::info('PrepareShopeeShippingLabelJob: createShippingDocument recoverable, lanjut check status', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                    'error' => $errCode,
                ]);
            }
        }

        try {
            $result = $shopee->getShippingDocumentResult(
                $shopId,
                $orderSn,
                $docType,
                $order->tracking_number,
                $order->package_number ?? null
            );
            $row = $result['response']['result_list'][0] ?? [];
            $status = strtoupper((string) ($row['status'] ?? ''));

            if ($status === 'READY') {
                $order->update([
                    'shipping_label_status' => 'ready',
                    'shipping_label_doc_type' => $docType,
                    'shipping_label_prepared_at' => now(),
                ]);
                Log::info('PrepareShopeeShippingLabelJob: shipping document READY', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                    'doc_type' => $docType,
                ]);

                $this->notifyBulkListeners();

                return;
            }

            if ($status === 'FAILED') {
                $order->update(['shipping_label_status' => 'failed']);
                Log::error('PrepareShopeeShippingLabelJob: shipping document FAILED', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                    'fail_error' => $row['fail_error'] ?? null,
                    'fail_msg' => $row['fail_message'] ?? null,
                ]);
                $this->notifyBulkListeners();
                throw new \RuntimeException('Shopee shipping document FAILED: '.($row['fail_message'] ?? $row['fail_error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'FAILED')) {
                throw $e;
            }
            Log::info('PrepareShopeeShippingLabelJob: dokumen belum siap atau get result gagal, jadwalkan retry: '.$e->getMessage(), [
                'order_id' => $order->id,
                'attempt' => $this->attempt,
            ]);
        }

        $nextAttempt = $this->attempt + 1;
        if ($nextAttempt < self::MAX_GLOBAL_ATTEMPTS) {
            $delaySeconds = self::RETRY_DELAYS[$this->attempt] ?? 30;
            Log::info('PrepareShopeeShippingLabelJob: label belum READY, dispatch delayed retry non-blocking', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
                'next_attempt' => $nextAttempt,
                'delay_sec' => $delaySeconds,
            ]);
            self::dispatch($order->id, $nextAttempt)
                ->onConnection(config('queue.routing.labels.connection', 'redis-long'))
                ->onQueue(config('queue.routing.labels.queue', 'labels'))
                ->delay(now()->addSeconds($delaySeconds));
        } else {
            $order->update(['shipping_label_status' => 'failed']);
            Log::error('PrepareShopeeShippingLabelJob: max global attempts tercapai, tandai failed', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);
            $this->notifyBulkListeners();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PrepareShopeeShippingLabelJob failed permanently', [
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

    private function notifyBulkListeners(): void
    {
        try {
            app(BulkShippingLabelService::class)->onOrderLabelReady($this->orderId);
        } catch (\Throwable $e) {
            Log::warning('PrepareShopeeShippingLabelJob: notifyBulkListeners gagal', [
                'order_id' => $this->orderId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
