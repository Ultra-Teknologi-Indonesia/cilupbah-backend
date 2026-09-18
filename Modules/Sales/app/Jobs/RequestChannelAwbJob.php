<?php

namespace Modules\Sales\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPrefetchService;

class RequestChannelAwbJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 900;

    private const TRACKING_RETRY_DELAYS = [3, 6, 12, 30, 60];

    public function __construct(
        public readonly string $orderId,
        public readonly int $trackingAttempt = 0,
        public readonly bool $requestReadyToShip = true,
        public readonly bool $prefetch = false,
    ) {
        $this->onConnection($prefetch
            ? config('shipping-label-prefetch.connection', 'redis-long')
            : config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue($prefetch
            ? config('shipping-label-prefetch.queue', 'label-prefetch')
            : config('queue.routing.label_awb.queue', 'label-awb'));
    }

    public function uniqueId(): string
    {

        return "order:{$this->orderId}:attempt:{$this->trackingAttempt}";
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

        $prefetchLock = null;
        if ($this->prefetch) {
            $prefetch = app(ShippingLabelPrefetchService::class);
            $prefetchLock = $prefetch->lock();

            if (! $prefetchLock->get()) {
                $delay = (int) config('shipping-label-prefetch.reschedule_seconds');
                $prefetch->deferExecution($order->id, 'global_lock_busy', $delay);
                self::dispatch($order->id, $this->trackingAttempt, true, true)
                    ->delay(now()->addSeconds($delay));

                return;
            }

            $gate = $prefetch->begin($order);
            if (! $gate['allowed']) {
                if ($gate['delay'] > 0) {
                    self::dispatch($order->id, $this->trackingAttempt, true, true)
                        ->delay(now()->addSeconds($gate['delay']));
                }

                $prefetchLock->release();

                return;
            }
        }

        if (! empty($order->tracking_number)) {
            app(BulkShippingLabelService::class)->onOrderAwbReady($order->id);
            $this->prepareLabel($order);
            $this->markPrefetchAwbReady($order);

            if ($prefetchLock) {
                $prefetchLock->release();
            }

            return;
        }

        if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'ready_to_ship', $order->salesorder_no)) {
            app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
                $order->id,
                BulkShippingLabelItem::REASON_CHANNEL_SYNC_PAUSED,
            );

            if ($prefetchLock && $prefetchLock->isOwnedByCurrentProcess()) {
                $prefetchLock->release();
            }

            return;
        }

        try {
            if ($this->trackingAttempt === 0 && $this->requestReadyToShip && $this->shouldRequestReadyToShip($order)) {
                $results = $fulfillment->readyToShip([$order->id]);
                $result = $results[0] ?? null;

                Log::info('RequestChannelAwbJob: readyToShip dispatched', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'source' => $source,
                    'status' => $result['status'] ?? 'unknown',
                    'message' => $result['message'] ?? null,
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
                $delay = $this->prefetch
                    ? app(ShippingLabelPrefetchService::class)->retry(
                        $order,
                        $this->trackingAttempt + 1,
                        'awb_not_ready',
                    )
                    : self::TRACKING_RETRY_DELAYS[$this->trackingAttempt];

                if ($delay === null) {
                    return;
                }
                Log::info('RequestChannelAwbJob: tracking belum tersedia, retry', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'next_attempt' => $this->trackingAttempt + 1,
                    'delay_seconds' => $delay,
                ]);
                self::dispatch($order->id, $this->trackingAttempt + 1, false, $this->prefetch)
                    ->delay(now()->addSeconds($delay));
            } else {
                Log::warning('RequestChannelAwbJob: menyerah, tracking tidak kunjung terbit', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'attempts' => $this->trackingAttempt + 1,
                ]);
                app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
                    $order->id,
                    BulkShippingLabelItem::REASON_AWB_TIMEOUT,
                );
            }
        } catch (\Throwable $e) {
            Log::error('RequestChannelAwbJob: error saat request AWB', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);
            if ($this->prefetch) {
                $delay = app(ShippingLabelPrefetchService::class)->retry(
                    $order,
                    $this->trackingAttempt + 1,
                    'marketplace_error',
                    $e->getMessage(),
                );

                if ($delay !== null) {
                    self::dispatch($order->id, $this->trackingAttempt + 1, true, true)
                        ->delay(now()->addSeconds($delay));
                }

                return;
            }

            throw $e;
        } finally {
            if ($prefetchLock && $prefetchLock->isOwnedByCurrentProcess()) {
                $prefetchLock->release();
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RequestChannelAwbJob failed permanently', [
            'order_id' => $this->orderId,
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
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'tracking_number' => $tn,
                ]);

                $this->prepareLabel($order);
                $this->markPrefetchAwbReady($order);

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('RequestChannelAwbJob: gagal fetch tracking_number', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function shouldRequestReadyToShip(SalesOrder $order): bool
    {

        if ($this->attempts() > 1) {
            return true;
        }

        $requestedAt = data_get($order->shipping_label_raw_data, 'bulk_label_awb.requested_at');
        $window = max(1, (int) config('bulk-labels.awb_request_dedupe_seconds', 300));

        if ($requestedAt !== null) {
            try {
                if (now()->diffInSeconds(Carbon::parse($requestedAt)) < $window) {
                    Log::info('RequestChannelAwbJob: join existing AWB preparation', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                    ]);

                    return false;
                }
            } catch (\Throwable) {

            }
        }

        $rawData = is_array($order->shipping_label_raw_data)
            ? $order->shipping_label_raw_data
            : [];
        $rawData['bulk_label_awb'] = [
            'requested_at' => now()->toIso8601String(),
        ];
        $order->forceFill(['shipping_label_raw_data' => $rawData])->saveQuietly();

        return true;
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
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'tracking_number' => $resolved['tracking_number'],
                ]);

                $this->prepareLabel($order);
                $this->markPrefetchAwbReady($order);

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('RequestChannelAwbJob: gagal fetch tracking_number', [
                'order_id' => $order->id,
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
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'tracking_number' => $tn,
                ]);

                $this->prepareLabel($order);
                $this->markPrefetchAwbReady($order);

                return true;
            }
        } catch (\Throwable $e) {
            Log::warning('RequestChannelAwbJob: gagal fetch Lazada tracking_number', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function markPrefetchAwbReady(SalesOrder $order): void
    {
        if ($this->prefetch) {
            app(ShippingLabelPrefetchService::class)->markAwbReady($order->id);
        }
    }

    private function prepareLabel(SalesOrder $order): void
    {
        match (strtolower((string) $order->source)) {
            'shopee' => PrepareShopeeShippingLabelJob::dispatch($order->id, 0, $this->prefetch),
            'tiktok' => PrepareTikTokShippingLabelJob::dispatch($order->id, 0, $this->prefetch),
            'lazada' => PrepareLazadaShippingLabelJob::dispatch($order->id, 0, $this->prefetch),
            default => null,
        };
    }
}
