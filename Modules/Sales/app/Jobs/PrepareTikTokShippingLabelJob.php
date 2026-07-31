<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Models\SalesOrder;

/**
 * Siapkan dokumen label kirim TikTok secara proaktif ("selalu dapat"): saat paket siap,
 * tarik doc_url tiap package lalu cache ke shipping_label_raw_data supaya operator tak
 * tergantung panggilan live saat cetak. Mirror konvensi status PrepareShopeeShippingLabelJob.
 */
class PrepareTikTokShippingLabelJob implements ShouldQueue
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

    public function handle(TikTokOrderService $tiktok): void
    {
        $order = SalesOrder::find($this->orderId);
        if (! $order) {
            return;
        }

        if (strtolower((string) $order->source) !== 'tiktok') {
            return;
        }

        if ($order->shipping_label_status === 'ready') {
            return;
        }

        if (empty($order->tracking_number)) {
            Log::info('PrepareTikTokShippingLabelJob: tracking_number kosong, skip', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
            ]);

            return;
        }

        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;

        if ($shopId === '' || $orderSn === '') {
            Log::warning('PrepareTikTokShippingLabelJob: channel_shop_id / channel_order_no kosong', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $order->update(['shipping_label_status' => 'preparing']);

        try {
            $packageIds = $tiktok->packageIdsForOrder($shopId, $orderSn);
        } catch (\Throwable $e) {
            $packageIds = [];
            Log::warning('PrepareTikTokShippingLabelJob: resolve package id gagal', [
                'order_id'  => $order->id,
                'order_sn'  => $orderSn,
                'exception' => $e->getMessage(),
            ]);
        }

        $documents = [];
        foreach ($packageIds as $packageId) {
            try {
                $res = $tiktok->getShippingDocument($shopId, (string) $packageId, 'SHIPPING_LABEL', 'A6');
                $docUrl = $res['data']['doc_url'] ?? $res['data']['url'] ?? null;

                if ($docUrl) {
                    $documents[] = ['package_id' => (string) $packageId, 'doc_url' => $docUrl];
                }
            } catch (\Throwable $e) {
                // Dokumen belum siap untuk package ini — biarkan retry menangani.
                Log::info('PrepareTikTokShippingLabelJob: dokumen package belum siap', [
                    'order_id'   => $order->id,
                    'package_id' => $packageId,
                    'exception'  => $e->getMessage(),
                ]);
            }
        }

        if (! empty($documents)) {
            $order->update([
                'shipping_label_status'      => 'ready',
                'shipping_label_doc_type'    => 'PDF',
                'shipping_label_prepared_at' => now(),
                'shipping_label_raw_data'    => ['channel' => 'tiktok', 'documents' => $documents],
            ]);

            Log::info('PrepareTikTokShippingLabelJob: shipping document READY', [
                'order_id'  => $order->id,
                'order_sn'  => $orderSn,
                'packages'  => count($documents),
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
            Log::warning('PrepareTikTokShippingLabelJob: label belum siap, retry', [
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
        Log::error('PrepareTikTokShippingLabelJob: max attempts tercapai, tandai failed', [
            'order_id' => $order->id,
            'order_sn' => $orderSn,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PrepareTikTokShippingLabelJob failed permanently', [
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
