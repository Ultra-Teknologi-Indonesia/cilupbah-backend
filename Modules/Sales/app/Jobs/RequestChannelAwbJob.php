<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Outbound\Support\InstantOrderClassifier;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;

class RequestChannelAwbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    private const TRACKING_RETRY_DELAYS = [30, 60, 300, 600];

    public function __construct(
        public readonly string $orderId,
        public readonly int $trackingAttempt = 0,
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
            app(BulkShippingLabelService::class)->onOrderAwbReady($order->id);

            return;
        }

        if (InstantOrderClassifier::needsManualDriverDispatch(
            $order->courier_name,
            $order->shipping_provider,
            $order->shipping_type,
        )) {
            Log::info('RequestChannelAwbJob: dilewati, kurir dipanggil manual lewat Pengiriman', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'courier_name'  => $order->courier_name,
            ]);

            app(BulkShippingLabelService::class)->onOrderAwbSkippedInstant($order->id);

            return;
        }

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'ready_to_ship', $order->salesorder_no)) {
            app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
                $order->id,
                BulkShippingLabelItem::REASON_CHANNEL_SYNC_PAUSED,
            );

            return;
        }

        try {
            if ($this->trackingAttempt === 0) {
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
            }

            $gotTracking = false;
            if ($source === 'shopee') {
                $gotTracking = $this->fetchShopeeTracking($order);
            } elseif ($source === 'tiktok') {
                $gotTracking = $this->fetchTiktokTracking($order);
            } elseif ($source === 'lazada') {
                $gotTracking = $this->fetchLazadaTracking($order);
            }

            if ($gotTracking) {
                app(BulkShippingLabelService::class)->onOrderAwbReady($order->id);
            } elseif (isset(self::TRACKING_RETRY_DELAYS[$this->trackingAttempt])) {
                $delay = self::TRACKING_RETRY_DELAYS[$this->trackingAttempt];
                Log::info('RequestChannelAwbJob: tracking belum tersedia, retry', [
                    'order_id'      => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'next_attempt'  => $this->trackingAttempt + 1,
                    'delay_seconds' => $delay,
                ]);
                self::dispatch($order->id, $this->trackingAttempt + 1)->delay(now()->addSeconds($delay));
            } else {
                Log::warning('RequestChannelAwbJob: menyerah, tracking tidak kunjung terbit', [
                    'order_id'      => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'attempts'      => $this->trackingAttempt + 1,
                ]);
                app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
                    $order->id,
                    BulkShippingLabelItem::REASON_AWB_TIMEOUT,
                );
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

        app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
            $this->orderId,
            BulkShippingLabelItem::REASON_AWB_TIMEOUT,
        );
    }

    private function fetchShopeeTracking(SalesOrder $order): bool
    {
        try {
            $shop = app(ChannelShopRepository::class)->findByShopId($order->channel_shop_id);
            if (! $shop) {
                return false;
            }

            $tn = app(ShopeeOrderService::class)->resolveTrackingNumber(
                $shop,
                (string) $order->channel_order_no,
                $order->channel_status ?? 'READY_TO_SHIP'
            );

            if ($tn) {
                $order->update(['tracking_number' => $tn]);
                Log::info('RequestChannelAwbJob: tracking_number disimpan', [
                    'order_id'        => $order->id,
                    'salesorder_no'   => $order->salesorder_no,
                    'tracking_number' => $tn,
                ]);

                PrepareShopeeShippingLabelJob::dispatch($order->id)
                    ->onQueue(config('queue.names.channel_sync'));

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('RequestChannelAwbJob: gagal fetch tracking_number', [
                'order_id'  => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function fetchTiktokTracking(SalesOrder $order): bool
    {
        try {
            $shop = app(ChannelShopRepository::class)->findByShopId($order->channel_shop_id);
            if (! $shop) {
                return false;
            }

            $resolved = app(TikTokOrderService::class)->resolveTrackingNumber(
                $shop,
                (string) $order->channel_order_no
            );

            if ($resolved && ! empty($resolved['tracking_number'])) {
                $update = ['tracking_number' => $resolved['tracking_number']];
                if (! empty($resolved['shipping_provider'])) {
                    $update['shipping_provider'] = $resolved['shipping_provider'];
                }

                $order->update($update);

                Log::info('RequestChannelAwbJob: tracking_number disimpan', [
                    'order_id'        => $order->id,
                    'salesorder_no'   => $order->salesorder_no,
                    'tracking_number' => $resolved['tracking_number'],
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('RequestChannelAwbJob: gagal fetch tracking_number', [
                'order_id'  => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function fetchLazadaTracking(SalesOrder $order): bool
    {
        try {
            app(LazadaOrderService::class)->pullOrderById(
                (string) $order->channel_shop_id,
                (string) $order->channel_order_no,
            );

            $fresh = SalesOrder::find($order->id);
            $tn = (string) ($fresh->tracking_number ?? '');

            if ($tn !== '') {
                Log::info('RequestChannelAwbJob: Lazada tracking_number tersimpan via pullOrderById', [
                    'order_id'        => $order->id,
                    'salesorder_no'   => $order->salesorder_no,
                    'tracking_number' => $tn,
                ]);

                PrepareLazadaShippingLabelJob::dispatch($order->id)
                    ->onQueue(config('queue.names.channel_sync'));

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('RequestChannelAwbJob: gagal fetch Lazada tracking_number', [
                'order_id'  => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
