<?php

namespace Modules\Sales\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPrefetchService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;

class RequestChannelAwbJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 900;

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

    public function handle(): void
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

        if (in_array($order->shipping_label_status, ['ready', 'preparing', 'self_design_required'], true)) {
            Log::info('RequestChannelAwbJob: label sudah siap atau sedang diproses, tidak request ulang', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'shipping_label_status' => $order->shipping_label_status,
                'has_tracking' => ! empty($order->tracking_number),
            ]);

            if (! empty($order->tracking_number)) {
                app(BulkShippingLabelService::class)->onOrderAwbReady($order->id);
            }

            if ($prefetchLock && $prefetchLock->isOwnedByCurrentProcess()) {
                $prefetchLock->release();
            }

            return;
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
            $requestMarketplace = $this->trackingAttempt === 0
                && $this->requestReadyToShip
                && ! $this->channelAlreadyShipped($order)
                && $this->shouldRequestReadyToShip($order);

            $gotTracking = match ($source) {
                'shopee' => $this->fetchShopeeTracking($order, $requestMarketplace),
                'tiktok' => $this->fetchTiktokTracking($order, $requestMarketplace),
                'lazada' => $this->fetchLazadaTracking($order, $requestMarketplace),
                default => false,
            };

            if ($gotTracking) {
                app(BulkShippingLabelService::class)->onOrderAwbReady($order->id);
            } else {
                Log::warning('RequestChannelAwbJob: tracking belum diterbitkan dalam satu request', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'source' => $source,
                ]);

                if ($this->attempts() < $this->tries) {
                    $attemptIndex = max(0, $this->attempts() - 1);
                    $delay = (int) ($this->backoff[$attemptIndex] ?? end($this->backoff));

                    Log::info('RequestChannelAwbJob: menjadwalkan percobaan berikutnya', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'source' => $source,
                        'attempt' => $this->attempts(),
                        'max_attempts' => $this->tries,
                        'delay_seconds' => $delay,
                    ]);

                    $this->release($delay);

                    return;
                }

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

    private function fetchShopeeTracking(SalesOrder $order, bool $requestMarketplace = false): bool
    {
        try {
            $shop = app(ChannelShopRepository::class)->findByShopId($order->channel_shop_id);
            if (! $shop) {
                return false;
            }

            $service = app(ShopeeOrderService::class);
            $orderSn = (string) $order->channel_order_no;
            $channelStatus = strtoupper((string) ($order->channel_status ?? 'READY_TO_SHIP'));

            $readStatus = in_array($channelStatus, [
                'READY_TO_SHIP',
                'PROCESSED',
                'SHIPPED',
                'TO_CONFIRM_RECEIVE',
                'COMPLETED',
            ], true) ? $channelStatus : 'READY_TO_SHIP';

            $preflightTracking = $service->resolveTrackingNumber(
                $shop,
                $orderSn,
                $readStatus,
            );

            if (filled($preflightTracking)) {
                $resolved = [
                    'tracking_number' => $preflightTracking,
                ];

                Log::info('RequestChannelAwbJob: Shopee AWB sudah tersedia, lewati POST /ship_order', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'tracking_number' => $preflightTracking,
                ]);
            } elseif ($requestMarketplace) {
                $resolved = $this->withRtsLock($order, true, function () use ($service, $shop, $order): ?array {
                    return $service->requestTrackingNumber(
                        (string) $order->channel_shop_id,
                        (string) $order->channel_order_no,
                        array_filter([
                            'preferred_method' => $shop->handover_method ?? null,
                        ], static fn ($value): bool => $value !== null && $value !== ''),
                    );
                });
            } else {
                $resolved = [
                    'tracking_number' => null,
                ];
            }
            $tn = is_array($resolved) ? ($resolved['tracking_number'] ?? null) : null;

            if ($tn) {
                $update = ['tracking_number' => $tn];
                if (! empty($resolved['channel_status'])) {
                    $update['channel_status'] = $resolved['channel_status'];
                }
                $order->update($update);
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

    private function channelAlreadyShipped(SalesOrder $order): bool
    {
        return in_array(strtoupper((string) $order->channel_status), [
            'PROCESSED',
            'AWAITING_COLLECTION',
            'SHIPPED',
            'IN_TRANSIT',
            'TO_CONFIRM_RECEIVE',
            'COMPLETED',
        ], true);
    }

    private function fetchTiktokTracking(SalesOrder $order, bool $requestMarketplace = false): bool
    {
        try {
            $shop = app(ChannelShopRepository::class)->findByShopId($order->channel_shop_id);
            if (! $shop) {
                return false;
            }

            $service = app(TikTokOrderService::class);
            $resolved = null;

            if ($requestMarketplace) {

                try {
                    $resolved = $service->resolveTrackingNumberDirect(
                        $shop,
                        (string) $order->channel_order_no,
                    );

                    if (is_array($resolved) && ! empty($resolved['tracking_number'])) {
                        Log::info('RequestChannelAwbJob: TikTok AWB sudah tersedia, lewati POST /ship', [
                            'order_id' => $order->id,
                            'salesorder_no' => $order->salesorder_no,
                            'tracking_number' => $resolved['tracking_number'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::debug('RequestChannelAwbJob: preflight TikTok tracking read gagal, lanjut request AWB', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'exception' => $e->getMessage(),
                    ]);
                    $resolved = null;
                }

                if (! is_array($resolved) || empty($resolved['tracking_number'])) {

                    $resolved = $this->withRtsLock($order, true, function () use ($service, $order): ?array {
                        return $service->requestTrackingNumber(
                            (string) $order->channel_shop_id,
                            (string) $order->channel_order_no,
                            null,
                            is_array($order->channel_package_ids) ? $order->channel_package_ids : [],
                        );
                    });
                }
            } else {
                $resolved = $service->resolveTrackingNumberDirect(
                    $shop,
                    (string) $order->channel_order_no,
                );
            }

            if ($resolved && ! empty($resolved['tracking_number'])) {
                $update = ['tracking_number' => $resolved['tracking_number']];
                if (! empty($resolved['shipping_provider'])) {
                    $update['shipping_provider'] = $resolved['shipping_provider'];
                }
                if (! empty($resolved['channel_status'])) {
                    $update['channel_status'] = $resolved['channel_status'];
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

    private function fetchLazadaTracking(SalesOrder $order, bool $requestMarketplace = false): bool
    {
        try {
            $service = app(LazadaOrderService::class);
            if ($requestMarketplace) {
                $resolved = $this->withRtsLock($order, true, function () use ($service, $order): ?array {
                    $shippingProvider = (string) ($order->delivery_option_id ?: $order->shipping_provider ?: '');

                    if ($shippingProvider === '') {
                        Log::warning('RequestChannelAwbJob: Lazada shipping_provider kosong, tidak menjalankan pack/RTS', [
                            'order_id' => $order->id,
                            'salesorder_no' => $order->salesorder_no,
                        ]);

                        return null;
                    }

                    return $service->requestTrackingNumber(
                        (string) $order->channel_shop_id,
                        (string) $order->channel_order_no,
                        $shippingProvider,
                    );
                });
            } else {
                $service->pullOrderById(
                    (string) $order->channel_shop_id,
                    (string) $order->channel_order_no,
                );
                $resolved = [];
            }

            $fresh = SalesOrder::find($order->id);
            $tn = (string) (($resolved['tracking_number'] ?? null) ?: ($fresh->tracking_number ?? ''));

            if ($tn !== '') {
                $update = ['tracking_number' => $tn];
                if (! empty($resolved['shipping_provider'])) {
                    $update['shipping_provider'] = $resolved['shipping_provider'];
                }
                if (! empty($resolved['channel_status'])) {
                    $update['channel_status'] = $resolved['channel_status'];
                }
                $order->update($update);

                Log::info('RequestChannelAwbJob: Lazada tracking_number tersimpan', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'tracking_number' => $tn,
                    'marketplace_action' => $requestMarketplace,
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

    private function withRtsLock(SalesOrder $order, bool $enabled, callable $callback): mixed
    {
        if (! $enabled) {
            return $callback();
        }

        $lock = Cache::lock("rts:{$order->id}", 30);
        if (! $lock->get()) {
            Log::info('RequestChannelAwbJob: RTS order sedang dikunci, tidak mengirim request kedua', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
            ]);

            return null;
        }

        try {
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }

    private function markPrefetchAwbReady(SalesOrder $order): void
    {
        if ($this->prefetch) {
            app(ShippingLabelPrefetchService::class)->markAwbReady($order->id);
        }
    }

    private function prepareLabel(SalesOrder $order): void
    {
        app(ShippingLabelPreparationDispatcher::class)->dispatch($order, $this->prefetch);
    }
}
