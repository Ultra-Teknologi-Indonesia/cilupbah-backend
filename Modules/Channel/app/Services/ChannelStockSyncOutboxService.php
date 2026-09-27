<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\DispatchChannelStockOutboxJob;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelStockSyncOutbox;
use Modules\Channel\Repositories\ChannelStockSyncOutboxRepository;
use Modules\Product\Models\ProductChannelMapping;

final class ChannelStockSyncOutboxService
{
    public function __construct(
        private readonly ChannelStockSyncOutboxRepository $repository,
        private readonly ChannelSyncSettingService $settings,
    ) {}

    public function request(
        ProductChannelMapping $mapping,
        string $action,
        string $queueTier = 'critical',
        bool $resetAttempts = false,
    ): ChannelStockSyncOutbox {
        if ($this->settings->isPaused()) {
            throw new \RuntimeException('Sinkronisasi channel sedang dijeda; push stok tidak diantrikan.');
        }

        [$syncStock, $syncPrice] = $this->axesFor($action);
        $outbox = $this->repository->request(
            $mapping,
            $syncStock,
            $syncPrice,
            $queueTier,
            $resetAttempts,
            now(),
        );

        $this->wakeDispatcher();

        return $outbox;
    }

    public function wakeDispatcher(): void
    {
        try {
            DispatchChannelStockOutboxJob::dispatch()->afterCommit();
        } catch (\Throwable $exception) {
            Log::warning('Dispatcher outbox stok gagal dijadwalkan.', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function requestByMappingId(string $mappingId, string $action, string $queueTier = 'critical'): ?ChannelStockSyncOutbox
    {
        $mapping = $this->repository->findMapping($mappingId);

        return $mapping === null ? null : $this->request($mapping, $action, $queueTier);
    }

    public function dispatchDue(?int $limit = null): array
    {
        $lock = Cache::lock('channel-stock-outbox:dispatch', 60);

        if (! $lock->get()) {
            return ['claimed' => 0, 'reaped' => 0, 'revived' => 0, 'byChannel' => []];
        }

        try {
            return $this->dispatchDueWithinLock($limit);
        } finally {
            $lock->release();
        }
    }

    public function shouldExecute(string $outboxId, int $version): bool
    {
        $result = $this->repository->beginAttempt(
            $outboxId,
            $version,
            max(1, (int) config('channel.stock_sync_max_attempts', 12)),
            now(),
        );

        if ($result === ChannelStockSyncOutboxRepository::TRANSITION_SUPERSEDED) {
            $this->wakeDispatcher();
        }

        return $result === ChannelStockSyncOutboxRepository::TRANSITION_UPDATED;
    }

    public function succeed(string $outboxId, int $version): void
    {
        $this->repository->markSucceeded($outboxId, $version, now());
        $this->wakeDispatcher();
    }

    public function defer(string $outboxId, int $version, string $reason, int $delaySeconds): void
    {
        $result = $this->repository->markDeferred(
            $outboxId,
            $version,
            $reason,
            $delaySeconds,
            max(1, (int) config('channel.stock_sync_max_attempts', 12)),
            now(),
        );

        if ($result === ChannelStockSyncOutboxRepository::TRANSITION_SUPERSEDED || $delaySeconds <= 30) {
            $this->wakeDispatcher();
        }
    }

    public function fail(string $outboxId, int $version, string $reason): void
    {
        $this->repository->markFailed($outboxId, $version, $reason, now());
        $this->wakeDispatcher();
    }

    public function skip(string $outboxId, int $version, string $reason): void
    {
        $this->repository->markSkipped($outboxId, $version, $reason, now());
        $this->wakeDispatcher();
    }

    public function retryDelaySeconds(int $attemptCount): int
    {
        $backoff = (array) config('channel.stock_sync_retry_backoff', [60, 300, 900, 1800]);
        $index = min(max(0, $attemptCount - 1), count($backoff) - 1);

        return max(1, (int) ($backoff[$index] ?? 1800));
    }

    public function attemptCount(string $outboxId): int
    {
        return $this->repository->attemptCount($outboxId);
    }

    public function expiredDispatchingCount(?Carbon $now = null): int
    {
        return $this->repository->expiredDispatchingCount($now ?? now());
    }

    public function reapExpiredLeases(?Carbon $now = null): int
    {
        return $this->repository->reapExpiredLeases($now ?? now());
    }

    private function dispatchDueWithinLock(?int $limit): array
    {
        if ($this->settings->isPaused()) {
            return ['claimed' => 0, 'reaped' => 0, 'revived' => 0, 'byChannel' => []];
        }

        $limit = max(1, $limit ?? (int) config('channel.stock_sync_dispatch_claim_limit', 50));
        $now = now();
        $reaped = $this->repository->reapExpiredLeases($now);
        $revived = $this->repository->reviveStrandedPendingDeliveries($now);
        $window = max(1, (int) config('channel.stock_sync_dispatch_window_seconds', 50));
        $leaseSeconds = max(60, (int) config('channel.stock_sync_lease_seconds', 600));
        $maxInFlightPerShop = max(1, (int) config('channel.stock_sync_max_inflight_per_shop', 1));
        $inFlightByShop = $this->repository->inFlightByShop($now);
        $saturatedShops = array_keys(array_filter(
            $inFlightByShop,
            static fn (int $count): bool => $count >= $maxInFlightPerShop,
        ));
        $candidates = $this->repository->dueCandidates($now, $saturatedShops, $limit * 3);
        $perShopSlots = [];
        $claimed = 0;
        $byChannel = [];

        foreach ($candidates as $outbox) {
            if ($claimed >= $limit) {
                break;
            }

            $rate = max(1, (int) config(
                'ratelimit.channel_api_per_second_by_channel.'.$outbox->channel_code,
                config('ratelimit.channel_api_per_second', 8),
            ));
            $shopKey = (string) $outbox->channel_shop_id;
            $inFlight = $inFlightByShop[$shopKey] ?? 0;

            if ($inFlight >= $maxInFlightPerShop) {
                continue;
            }

            $slot = $perShopSlots[$shopKey] ?? 0;

            if ($slot >= $rate * $window) {
                continue;
            }

            $version = (int) $outbox->requested_version;

            if (! $this->repository->claim((string) $outbox->id, $version, $now, $leaseSeconds)) {
                continue;
            }

            $delaySeconds = intdiv($slot, $rate);
            $perShopSlots[$shopKey] = $slot + 1;
            $inFlightByShop[$shopKey] = $inFlight + 1;

            try {
                SyncProductToChannelJob::dispatch(
                    (string) $outbox->product_id,
                    (string) $outbox->channel_shop_id,
                    $outbox->action(),
                    null,
                    null,
                    null,
                    (string) $outbox->queue_tier,
                    (string) $outbox->product_channel_mapping_id,
                    (string) $outbox->id,
                    $version,
                )->delay($now->copy()->addSeconds($delaySeconds));
            } catch (\Throwable $exception) {
                $this->defer((string) $outbox->id, $version, 'Gagal menaruh pekerjaan ke antrean: '.$exception->getMessage(), 30);
                Log::error('Gagal mengirim channel stock outbox ke queue.', [
                    'outbox_id' => $outbox->id,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $claimed++;
            $byChannel[$outbox->channel_code] = ($byChannel[$outbox->channel_code] ?? 0) + 1;
        }

        return compact('claimed', 'reaped', 'revived', 'byChannel');
    }

    private function axesFor(string $action): array
    {
        return match ($action) {
            'sync_stock' => [true, false],
            'sync_price' => [false, true],
            'sync_price_stock' => [true, true],
            default => throw new \InvalidArgumentException("Aksi outbox stok tidak didukung: {$action}"),
        };
    }
}
