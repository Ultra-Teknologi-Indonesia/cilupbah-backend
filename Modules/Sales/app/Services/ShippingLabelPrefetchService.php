<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\ShippingLabelPrefetch;

class ShippingLabelPrefetchService
{
    public function eligibility(SalesOrder $order): array
    {
        if (! config('shipping-label-prefetch.enabled')) {
            return ['eligible' => false, 'reason' => 'feature_disabled'];
        }

        $source = strtolower(trim((string) $order->source));

        if (! in_array($source, ['shopee', 'tiktok', 'lazada'], true)
        ) {
            return ['eligible' => false, 'reason' => 'unsupported_source'];
        }

        if ($order->channel_instant !== false) {
            return ['eligible' => false, 'reason' => $order->channel_instant === null ? 'unknown_shipping_type' : 'instant_or_sameday'];
        }

        $channelStatus = strtoupper(trim((string) $order->channel_status));
        if (! in_array($channelStatus, [
            'READY_TO_SHIP',
            'AWAITING_SHIPMENT',
            'PROCESSED',
            'AWAITING_COLLECTION',
        ], true)) {
            return ['eligible' => false, 'reason' => $channelStatus === '' ? 'channel_status_unknown' : 'channel_not_ready_to_ship'];
        }

        if (! $order->is_paid || $order->is_shadow || $order->is_canceled || $order->cancel_requested_at !== null) {
            return ['eligible' => false, 'reason' => 'order_not_safe'];
        }

        if ($order->status !== 'reserved' || $order->hasStockShortfall()) {
            return ['eligible' => false, 'reason' => 'stock_not_confirmed'];
        }

        if (empty($order->channel_order_no) || empty($order->channel_shop_id)) {
            return ['eligible' => false, 'reason' => 'missing_channel_reference'];
        }

        $lazadaShippingProvider = (string) ($order->delivery_option_id ?: $order->shipping_provider ?: '');
        if ($source === 'lazada'
            && in_array($channelStatus, ['READY_TO_SHIP', 'AWAITING_SHIPMENT'], true)
            && trim($lazadaShippingProvider) === '') {
            return ['eligible' => false, 'reason' => 'missing_shipping_provider'];
        }

        if (in_array($order->shipping_label_status, ['ready', 'preparing', 'self_design_required'], true)) {
            return ['eligible' => false, 'reason' => 'label_already_ready'];
        }

        return ['eligible' => true, 'reason' => 'eligible'];
    }

    public function schedule(SalesOrder $order): bool
    {
        $eligibility = $this->eligibility($order);
        if (! $eligibility['eligible']) {
            return false;
        }

        $prefetch = ShippingLabelPrefetch::query()->firstOrCreate(
            ['order_id' => $order->id],
            ['status' => ShippingLabelPrefetch::STATUS_QUEUED, 'last_reason' => 'payment_and_stock_confirmed'],
        );

        if (in_array($prefetch->status, [ShippingLabelPrefetch::STATUS_AWB_READY, ShippingLabelPrefetch::STATUS_SKIPPED], true)) {
            return false;
        }

        if (! $prefetch->wasRecentlyCreated
            && $prefetch->status === ShippingLabelPrefetch::STATUS_QUEUED
            && $prefetch->next_attempt_at?->greaterThan(now())) {
            return false;
        }

        if ($prefetch->status === ShippingLabelPrefetch::STATUS_PROCESSING
            && $prefetch->locked_at?->greaterThan(now()->subMinutes((int) config('shipping-label-prefetch.stale_processing_minutes')))
        ) {
            return false;
        }

        $prefetch->forceFill([
            'status' => ShippingLabelPrefetch::STATUS_QUEUED,
            'next_attempt_at' => null,
            'locked_at' => null,
            'last_reason' => 'queued',
            'last_error' => null,
        ])->save();

        RequestChannelAwbJob::dispatch((string) $order->id, 0, true, true)->afterCommit();

        return true;
    }

    public function begin(SalesOrder $order): array
    {
        $eligibility = $this->eligibility($order);
        $prefetch = ShippingLabelPrefetch::query()->firstOrCreate(['order_id' => $order->id]);

        if (! $eligibility['eligible']) {
            $prefetch->forceFill([
                'status' => ShippingLabelPrefetch::STATUS_SKIPPED,
                'last_reason' => $eligibility['reason'],
                'locked_at' => null,
            ])->save();

            return ['allowed' => false, 'delay' => 0, 'reason' => $eligibility['reason']];
        }

        $pendingManual = app('queue')->connection(config('queue.routing.label_awb.connection', 'redis-long'))
            ->size(config('queue.routing.label_awb.queue', 'label-awb'));
        $pendingManual += app('queue')->connection(config('queue.routing.labels.connection', 'redis-long'))
            ->size(config('queue.routing.labels.queue', 'labels'));
        $threshold = (int) config('shipping-label-prefetch.manual_queue_pause_threshold', 0);

        if ($pendingManual > $threshold) {
            return $this->defer($prefetch, 'manual_queue_priority', (int) config('shipping-label-prefetch.reschedule_seconds'));
        }

        $interval = (int) config('shipping-label-prefetch.global_interval_seconds');
        $rateKey = 'shipping-label-prefetch:global-rate';
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            return $this->defer($prefetch, 'rate_limited', max(1, RateLimiter::availableIn($rateKey)));
        }

        RateLimiter::hit($rateKey, $interval);
        $prefetch->forceFill([
            'status' => ShippingLabelPrefetch::STATUS_PROCESSING,
            'attempts' => $prefetch->attempts + 1,
            'locked_at' => now(),
            'next_attempt_at' => null,
            'last_reason' => 'processing',
            'last_error' => null,
        ])->save();

        return ['allowed' => true, 'delay' => 0, 'reason' => 'processing'];
    }

    public function lock(): mixed
    {
        return Cache::lock('shipping-label-prefetch:global-execution', 180);
    }

    public function markAwbReady(string $orderId): void
    {
        ShippingLabelPrefetch::query()->where('order_id', $orderId)->update([
            'status' => ShippingLabelPrefetch::STATUS_AWB_READY,
            'locked_at' => null,
            'next_attempt_at' => null,
            'last_reason' => 'awb_ready',
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    public function deferExecution(string $orderId, string $reason, int $delay): void
    {
        ShippingLabelPrefetch::query()->where('order_id', $orderId)->update([
            'status' => ShippingLabelPrefetch::STATUS_QUEUED,
            'locked_at' => null,
            'next_attempt_at' => now()->addSeconds($delay),
            'last_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    public function retry(SalesOrder $order, int $attempt, string $reason, ?string $error = null): ?int
    {
        $prefetch = ShippingLabelPrefetch::query()->firstOrCreate(['order_id' => $order->id]);
        $delays = config('shipping-label-prefetch.retry_delays_seconds', [60]);
        $maxAttempts = (int) config('shipping-label-prefetch.max_attempts', 5);

        if ($attempt >= $maxAttempts) {
            $prefetch->forceFill([
                'status' => ShippingLabelPrefetch::STATUS_FAILED,
                'locked_at' => null,
                'last_reason' => $reason,
                'last_error' => Str::limit((string) $error, 1000),
            ])->save();

            return null;
        }

        $delay = (int) ($delays[min($attempt, count($delays) - 1)] ?? 3600);
        $prefetch->forceFill([
            'status' => ShippingLabelPrefetch::STATUS_QUEUED,
            'locked_at' => null,
            'next_attempt_at' => now()->addSeconds($delay),
            'last_reason' => $reason,
            'last_error' => Str::limit((string) $error, 1000),
        ])->save();

        return $delay;
    }

    public function dispatchDue(int $limit): int
    {
        $staleAt = now()->subMinutes((int) config('shipping-label-prefetch.stale_processing_minutes'));
        $states = ShippingLabelPrefetch::query()
            ->with('order')
            ->where(function ($query) use ($staleAt): void {
                $query->where(function ($due): void {
                    $due->where('status', ShippingLabelPrefetch::STATUS_QUEUED)
                        ->where(function ($next): void {
                            $next->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($processing) use ($staleAt): void {
                    $processing->where('status', ShippingLabelPrefetch::STATUS_PROCESSING)
                        ->where('locked_at', '<', $staleAt);
                });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($states as $state) {
            if ($state->order && $this->schedule($state->order)) {
                $count++;
            }
        }

        return $count;
    }

    private function defer(ShippingLabelPrefetch $prefetch, string $reason, int $delay): array
    {
        $prefetch->forceFill([
            'status' => ShippingLabelPrefetch::STATUS_QUEUED,
            'locked_at' => null,
            'next_attempt_at' => now()->addSeconds($delay),
            'last_reason' => $reason,
        ])->save();

        return ['allowed' => false, 'delay' => $delay, 'reason' => $reason];
    }
}
