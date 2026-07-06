<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Models\SalesOrder;

class PrepareShopeeShippingLabelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    private const MAX_GLOBAL_ATTEMPTS = 3;

    private const POLL_DELAYS = [5, 10, 15, 30, 60, 120];

    public function __construct(
        public readonly string $orderId,
        public readonly int $attempt = 0,
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
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

        if (empty($order->tracking_number)) {
            Log::info('PrepareShopeeShippingLabelJob: tracking_number kosong, skip', [
                'order_id'      => $order->id,
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

        try {
            $shop = (object) ['shop_id' => $shopId];
            $selfDesign = $shopee->checkAllowSelfDesignAwb($shop, $orderSn);
        } catch (\Throwable $e) {
            $selfDesign = false;
            Log::warning('PrepareShopeeShippingLabelJob: checkAllowSelfDesignAwb gagal, fallback ke flow normal', [
                'order_id'  => $order->id,
                'order_sn'  => $orderSn,
                'exception' => $e->getMessage(),
            ]);
        }

        if ($selfDesign) {
            $order->update([
                'shipping_label_status'      => 'self_design_required',
                'shipping_label_doc_type'    => null,
                'shipping_label_prepared_at' => now(),
            ]);

            Log::info('PrepareShopeeShippingLabelJob: channel mengharuskan self-design AWB, tidak ada PDF dari sistem', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);

            return;
        }

        try {
            $docType = $shopee->resolveSupportedDocType($shopId, $orderSn, 'NORMAL_AIR_WAYBILL');
        } catch (\Throwable $e) {
            $docType = 'NORMAL_AIR_WAYBILL';
            Log::warning('PrepareShopeeShippingLabelJob: resolveSupportedDocType gagal, pakai default', [
                'order_id'  => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        $create = $shopee->createShippingDocument($shopId, $orderSn, $docType);
        if (! empty($create['error'])) {
            $failDetail = $create['response']['result_list'][0]['fail_message']
                ?? $create['response']['result_list'][0]['fail_error']
                ?? ($create['message'] ?? null);

            $errCode = (string) $create['error'];
            $recoverable = str_contains($errCode, 'duplicate') || str_contains($errCode, 'already');

            if (! $recoverable) {
                $order->update(['shipping_label_status' => 'failed']);
                Log::error('PrepareShopeeShippingLabelJob: createShippingDocument gagal', [
                    'order_id'  => $order->id,
                    'order_sn'  => $orderSn,
                    'doc_type'  => $docType,
                    'error'     => $errCode,
                    'message'   => $failDetail,
                ]);
                throw new \RuntimeException("Shopee createShippingDocument gagal: {$errCode} {$failDetail}");
            }

            Log::info('PrepareShopeeShippingLabelJob: createShippingDocument recoverable, lanjut polling', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
                'error'    => $errCode,
            ]);
        }

        foreach (self::POLL_DELAYS as $delay) {
            sleep($delay);

            $result = $shopee->getShippingDocumentResult($shopId, $orderSn, $docType);
            $row = $result['response']['result_list'][0] ?? [];
            $status = strtoupper((string) ($row['status'] ?? ''));

            if ($status === 'READY') {
                $order->update([
                    'shipping_label_status'      => 'ready',
                    'shipping_label_doc_type'    => $docType,
                    'shipping_label_prepared_at' => now(),
                ]);
                Log::info('PrepareShopeeShippingLabelJob: shipping document READY', [
                    'order_id' => $order->id,
                    'order_sn' => $orderSn,
                    'doc_type' => $docType,
                ]);

                return;
            }

            if ($status === 'FAILED') {
                $order->update(['shipping_label_status' => 'failed']);
                Log::error('PrepareShopeeShippingLabelJob: shipping document FAILED', [
                    'order_id'   => $order->id,
                    'order_sn'   => $orderSn,
                    'fail_error' => $row['fail_error'] ?? null,
                    'fail_msg'   => $row['fail_message'] ?? null,
                ]);
                throw new \RuntimeException('Shopee shipping document FAILED: ' . ($row['fail_message'] ?? $row['fail_error'] ?? 'unknown'));
            }

        }

        $order->update(['shipping_label_status' => 'not_ready']);

        $nextAttempt = $this->attempt + 1;
        if ($nextAttempt < self::MAX_GLOBAL_ATTEMPTS) {
            Log::warning('PrepareShopeeShippingLabelJob: label belum READY setelah polling, retry', [
                'order_id'     => $order->id,
                'order_sn'     => $orderSn,
                'next_attempt' => $nextAttempt,
            ]);
            self::dispatch($order->id, $nextAttempt)
                ->onQueue(config('queue.names.channel_sync'))
                ->delay(now()->addMinutes(5));
        } else {
            Log::error('PrepareShopeeShippingLabelJob: max global attempts tercapai, menyerah', [
                'order_id' => $order->id,
                'order_sn' => $orderSn,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PrepareShopeeShippingLabelJob failed permanently', [
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
