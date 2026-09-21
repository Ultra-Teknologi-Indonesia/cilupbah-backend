<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPrefetchService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Modules\Sales\Support\ChannelOperationLedger;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;

class RequestChannelAwbJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_VERIFICATION_ATTEMPTS = 10;

    public int $tries = 8;

    public array $backoff = [15, 30, 60, 120, 300, 600, 900];

    public int $timeout = 120;

    public int $uniqueFor = 1800;

    private bool $awaitingVerification = false;

    private bool $requiresShipmentRetry = false;

    public function __construct(
        public readonly string $orderId,
        public readonly int $trackingAttempt = 0,
        public readonly bool $requestReadyToShip = true,
        public readonly bool $prefetch = false,
        public readonly bool $verificationOnly = false,
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

        return "order:{$this->orderId}:attempt:{$this->trackingAttempt}:".($this->verificationOnly ? 'verify' : 'request');
    }

    public function handle(): void
    {
        $order = ChannelOrderSideEffectGuard::active($this->orderId, 'request_awb');

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
                $this->markMarketplaceAwbSucceeded($order, (string) $order->tracking_number);
                app(BulkShippingLabelService::class)->onOrderAwbReady($order->id);
            }

            if ($prefetchLock && $prefetchLock->isOwnedByCurrentProcess()) {
                $prefetchLock->release();
            }

            return;
        }

        if (! empty($order->tracking_number)) {
            $this->markMarketplaceAwbSucceeded($order, (string) $order->tracking_number);
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
                && ($source === 'tiktok' || ! $this->channelAlreadyShipped($order))
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

                if ($this->requiresShipmentRetry) {
                    $delay = $this->verificationDelaySeconds();

                    if ($this->trackingAttempt === 0 && ! $this->verificationOnly) {
                        $this->release($delay);

                        return;
                    }

                    self::dispatch($order->id, 0, true, $this->prefetch, false)
                        ->delay(now()->addSeconds($delay));

                    return;
                }

                if ($this->awaitingVerification || $this->verificationOnly) {
                    if ($this->trackingAttempt < self::MAX_VERIFICATION_ATTEMPTS) {
                        self::dispatch(
                            $order->id,
                            $this->trackingAttempt + 1,
                            false,
                            $this->prefetch,
                            true,
                        )->delay(now()->addSeconds($this->verificationDelaySeconds()));
                    } else {
                        Log::warning('RequestChannelAwbJob: batas verifikasi cepat tercapai tanpa tracking number; rekonsiliasi terjadwal tetap membaca status tanpa POST ulang', [
                            'order_id' => $order->id,
                            'salesorder_no' => $order->salesorder_no,
                            'source' => $source,
                            'verification_attempts' => $this->trackingAttempt,
                        ]);
                    }

                    return;
                }

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

        if (ChannelOrderSideEffectGuard::active($this->orderId, 'mark_awb_failed') !== null) {
            app(BulkShippingLabelService::class)->onOrderAwbGaveUp(
                $this->orderId,
                BulkShippingLabelItem::REASON_AWB_TIMEOUT,
            );
        }
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
                $resolved = $this->withRtsLock($order, true, function (SalesOrder $freshOrder) use ($service, $shop): ?array {
                    return $this->requestMarketplaceAwb($freshOrder, function () use ($service, $freshOrder, $shop): array {
                        return $service->requestTrackingNumber(
                            (string) $freshOrder->channel_shop_id,
                            (string) $freshOrder->channel_order_no,
                            array_filter([
                                'preferred_method' => $shop->handover_method ?? null,
                            ], static fn ($value): bool => $value !== null && $value !== ''),
                        );
                    });
                });
            } else {
                $resolved = [
                    'tracking_number' => null,
                ];
            }
            $tn = is_array($resolved) ? ($resolved['tracking_number'] ?? null) : null;

            if ($tn) {
                $order = ChannelOrderSideEffectGuard::active($order->id, 'persist_awb');
                if ($order === null) {
                    return false;
                }

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

                $this->markMarketplaceAwbSucceeded($order, (string) $tn);
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

        return true;
    }

    private function verificationDelaySeconds(): int
    {
        $delays = array_values((array) config(
            'bulk-labels.awb_verification_delays',
            [2, 5, 10, 20, 30, 60],
        ));

        if ($delays === []) {
            return max(1, (int) config('bulk-labels.awb_verification_delay_seconds', 60));
        }

        $index = min($this->trackingAttempt, count($delays) - 1);

        return max(1, (int) $delays[$index]);
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

                    $snapshot = $service->getOrderFulfillmentSnapshot(
                        $shop,
                        (string) $order->channel_order_no,
                    );

                    if (
                        ! empty($snapshot['tracking_number'])
                        && empty($snapshot['has_pending_package'])
                    ) {
                        $resolved = [
                            'tracking_number' => $snapshot['tracking_number'],
                            'shipping_provider' => $snapshot['shipping_provider'] ?? null,
                            'channel_status' => $snapshot['status'] ?? null,
                            'all_packages_shipped' => (bool) ($snapshot['all_packages_shipped'] ?? true),
                        ];

                        Log::info('RequestChannelAwbJob: TikTok AWB sudah tersedia, lewati POST /ship', [
                            'order_id' => $order->id,
                            'salesorder_no' => $order->salesorder_no,
                            'tracking_number' => $snapshot['tracking_number'],
                        ]);
                    } elseif (
                        empty($snapshot['has_pending_package'])
                        && $this->tiktokAlreadyShipped($snapshot['status'] ?? null)
                    ) {

                        $this->awaitingVerification = true;
                        $resolved = [
                            'tracking_number' => null,
                            'channel_status' => $snapshot['status'] ?? null,
                            'all_packages_shipped' => (bool) ($snapshot['all_packages_shipped'] ?? true),
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('RequestChannelAwbJob: preflight TikTok order read gagal; POST /ship dibatalkan', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'exception' => $e->getMessage(),
                    ]);
                    $this->markTikTokPreflightUncertain($order, $e);
                    $this->awaitingVerification = true;

                    return false;
                }

                if ($resolved === null) {

                    $resolved = $this->withRtsLock($order, true, function (SalesOrder $freshOrder) use ($service): ?array {
                        return $this->requestMarketplaceAwb($freshOrder, function () use ($service, $freshOrder): array {
                            return $service->requestTrackingNumber(
                                (string) $freshOrder->channel_shop_id,
                                (string) $freshOrder->channel_order_no,
                                null,
                                is_array($freshOrder->channel_package_ids) ? $freshOrder->channel_package_ids : [],
                            );
                        });
                    });
                }
            } else {
                $snapshot = $service->getOrderFulfillmentSnapshot(
                    $shop,
                    (string) $order->channel_order_no,
                );

                if (! empty($snapshot['tracking_number'])) {
                    $resolved = [
                        'tracking_number' => $snapshot['tracking_number'],
                        'shipping_provider' => $snapshot['shipping_provider'] ?? null,
                        'channel_status' => $snapshot['status'] ?? null,
                        'all_packages_shipped' => (bool) ($snapshot['all_packages_shipped'] ?? true),
                    ];
                } elseif (
                    empty($snapshot['has_pending_package'])
                    && $this->tiktokAlreadyShipped($snapshot['status'] ?? null)
                ) {
                    $this->awaitingVerification = true;
                    $resolved = [
                        'tracking_number' => null,
                        'channel_status' => $snapshot['status'] ?? null,
                        'all_packages_shipped' => (bool) ($snapshot['all_packages_shipped'] ?? true),
                    ];
                } else {
                    $resolved = null;
                    $this->requiresShipmentRetry = $this->verificationOnly
                        && $this->tiktokCanRequestShipment($snapshot);
                }
            }

            if ($resolved && ! empty($resolved['tracking_number'])) {
                $order = ChannelOrderSideEffectGuard::active($order->id, 'persist_awb');
                if ($order === null) {
                    return false;
                }

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

                if (array_key_exists('all_packages_shipped', $resolved) && ! $resolved['all_packages_shipped']) {
                    $this->requiresShipmentRetry = true;

                    Log::warning('RequestChannelAwbJob: TikTok masih memiliki package yang belum di-ship', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                    ]);

                    return false;
                }

                $this->markMarketplaceAwbSucceeded($order, (string) $resolved['tracking_number']);
                if (
                    strtolower((string) $order->source) === 'tiktok'
                    && $order->driver_call_status === 'pending'
                ) {
                    $order->update([
                        'driver_call_status' => 'success',
                        'driver_call_message' => null,
                    ]);
                }
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

    private function tiktokAlreadyShipped(?string $status): bool
    {
        return in_array(strtoupper((string) $status), [
            'PROCESSED',
            'AWAITING_COLLECTION',
            'SHIPPED',
            'IN_TRANSIT',
            'TO_CONFIRM_RECEIVE',
            'COMPLETED',
        ], true);
    }

    private function tiktokCanRequestShipment(array $snapshot): bool
    {
        if (! empty($snapshot['has_pending_package'])) {
            return true;
        }

        return in_array(strtoupper((string) ($snapshot['status'] ?? '')), [
            'AWAITING_SHIPMENT',
            'READY_TO_SHIP',
        ], true);
    }

    private function markTikTokPreflightUncertain(SalesOrder $order, \Throwable $exception): void
    {
        $claim = ChannelOperationLedger::claim($order, 'request_awb');
        if ($claim['should_execute']) {
            ChannelOperationLedger::markUncertain($claim['attempt'], $exception);
        }
    }

    private function fetchLazadaTracking(SalesOrder $order, bool $requestMarketplace = false): bool
    {
        try {
            $service = app(LazadaOrderService::class);
            if ($requestMarketplace) {
                $resolved = $this->withRtsLock($order, true, function (SalesOrder $freshOrder) use ($service): ?array {
                    $shippingProvider = (string) ($freshOrder->delivery_option_id ?: $freshOrder->shipping_provider ?: '');

                    if ($shippingProvider === '') {
                        Log::warning('RequestChannelAwbJob: Lazada shipping_provider kosong, tidak menjalankan pack/RTS', [
                            'order_id' => $freshOrder->id,
                            'salesorder_no' => $freshOrder->salesorder_no,
                        ]);

                        return null;
                    }

                    if (
                        $freshOrder->driver_call_status === 'pending'
                        && $freshOrder->shipping_label_status !== 'ready'
                    ) {
                        ProcessLazadaFulfillmentJob::dispatch(
                            (string) $freshOrder->channel_shop_id,
                            (string) $freshOrder->channel_order_no,
                            $shippingProvider,
                            'dropship',
                            $freshOrder->tracking_number ?: null,
                            null,
                        )->afterCommit();

                        $this->awaitingVerification = true;

                        return null;
                    }

                    return $this->requestMarketplaceAwb($freshOrder, function () use ($service, $freshOrder, $shippingProvider): array {
                        return $service->requestTrackingNumber(
                            (string) $freshOrder->channel_shop_id,
                            (string) $freshOrder->channel_order_no,
                            $shippingProvider,
                        );
                    });
                });
            } else {
                $service->pullOrderById(
                    (string) $order->channel_shop_id,
                    (string) $order->channel_order_no,
                );
                $resolved = [];
            }

            $fresh = SalesOrder::find($order->id);
            $tn = (string) (($resolved['tracking_number'] ?? null) ?: ($fresh?->tracking_number ?? ''));

            if ($tn !== '') {
                $order = ChannelOrderSideEffectGuard::active($order->id, 'persist_awb');
                if ($order === null) {
                    return false;
                }

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

                $this->markMarketplaceAwbSucceeded($order, $tn);
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
        if (! $enabled || strtolower((string) $order->source) === 'tiktok') {
            $freshOrder = ChannelOrderSideEffectGuard::active($order->id, 'request_awb');

            return $freshOrder === null ? null : $callback($freshOrder);
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
            $freshOrder = ChannelOrderSideEffectGuard::active($order->id, 'request_awb');

            return $freshOrder === null ? null : $callback($freshOrder);
        } finally {
            optional($lock)->release();
        }
    }

    private function requestMarketplaceAwb(SalesOrder $order, callable $callback): ?array
    {
        $claim = ChannelOperationLedger::claim($order, 'request_awb');

        if (! $claim['should_execute']) {
            $this->awaitingVerification = in_array(
                $claim['attempt']->status,
                [
                    ChannelOperationAttempt::STATUS_ACCEPTED,
                    ChannelOperationAttempt::STATUS_UNCERTAIN,
                    ChannelOperationAttempt::STATUS_SENDING,
                ],
                true,
            );

            Log::warning('RequestChannelAwbJob: request AWB tidak diulang sebelum hasil channel diverifikasi.', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'ledger_status' => $claim['attempt']->status,
            ]);

            return null;
        }

        try {
            $result = $callback();
            $accepted = ! empty($result['accepted'])
                || ! empty($result['shipped'])
                || strtoupper((string) ($result['channel_status'] ?? '')) === 'PROCESSED';
            $allPackagesShipped = ! array_key_exists('all_packages_shipped', $result)
                || ! empty($result['all_packages_shipped']);

            if ($accepted && $allPackagesShipped) {
                ChannelOperationLedger::markAccepted($claim['attempt'], $result);

                $this->awaitingVerification = empty($result['tracking_number']);
            } elseif ($accepted) {
                ChannelOperationLedger::markRetryable(
                    $claim['attempt'],
                    'Sebagian package TikTok belum diterima; hanya package yang tersisa akan dicoba ulang.',
                    $result,
                );
            } else {
                ChannelOperationLedger::markRetryable(
                    $claim['attempt'],
                    (string) ($result['message'] ?? $result['error'] ?? 'Channel belum menerima request AWB.'),
                    $result,
                );
            }

            return $result;
        } catch (\Throwable $exception) {
            ChannelOperationLedger::markUncertain($claim['attempt'], $exception);
            $this->awaitingVerification = true;

            throw $exception;
        }
    }

    private function markMarketplaceAwbSucceeded(SalesOrder $order, string $trackingNumber): void
    {
        ChannelOperationLedger::markSucceededWhenVerified($order, 'request_awb', [
            'tracking_number' => $trackingNumber,
        ]);
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
