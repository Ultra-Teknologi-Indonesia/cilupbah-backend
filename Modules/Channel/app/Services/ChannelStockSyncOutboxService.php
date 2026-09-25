<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\DispatchChannelStockOutboxJob;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelStockSyncOutbox;
use Modules\Channel\Repositories\ChannelStockSyncOutboxRepository;
use Modules\Product\Models\ProductChannelMapping;

class ChannelStockSyncOutboxService
{
    public function request(
        ProductChannelMapping $mapping,
        string $action,
        string $queueTier = 'critical',
        bool $resetAttempts = false,
    ): ChannelStockSyncOutbox {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            throw new \RuntimeException('Sinkronisasi channel sedang dijeda; push stok tidak diantrikan.');
        }

        [$syncStock, $syncPrice] = $this->axesFor($action);
        $now = now();

        $outbox = DB::transaction(function () use ($mapping, $syncStock, $syncPrice, $queueTier, $resetAttempts, $now): ChannelStockSyncOutbox {
            // Lock an existing parent as well: a missing outbox row cannot be
            // locked, and two first requests must not race the unique index.
            app(ChannelStockSyncOutboxRepository::class)->lockMapping((string) $mapping->id);
            $outbox = ChannelStockSyncOutbox::query()
                ->where('product_channel_mapping_id', $mapping->id)
                ->lockForUpdate()
                ->first();

            if ($outbox === null) {
                return ChannelStockSyncOutbox::create([
                    'product_channel_mapping_id' => $mapping->id,
                    'product_id' => $mapping->product_id,
                    'channel_shop_id' => $mapping->channel_shop_id,
                    'sync_stock' => $syncStock,
                    'sync_price' => $syncPrice,
                    'queue_tier' => $queueTier,
                    'status' => ChannelStockSyncOutbox::STATUS_PENDING,
                    'requested_version' => 1,
                    'next_attempt_at' => $now,
                ]);
            }

            $changes = [
                'sync_stock' => $outbox->sync_stock || $syncStock,
                'sync_price' => $outbox->sync_price || $syncPrice,
                'queue_tier' => $this->higherPriorityTier($outbox->queue_tier, $queueTier),
                'requested_version' => $outbox->requested_version + 1,
                'last_error' => null,
                'updated_at' => $now,
            ];

            if (($resetAttempts || $outbox->status === ChannelStockSyncOutbox::STATUS_SUCCEEDED)
                && $outbox->status !== ChannelStockSyncOutbox::STATUS_DISPATCHING) {
                $changes += [
                    'attempt_count' => 0,
                    'completed_at' => null,
                ];
            }

            if ($outbox->status !== ChannelStockSyncOutbox::STATUS_DISPATCHING) {
                $changes += [
                    'status' => ChannelStockSyncOutbox::STATUS_PENDING,
                    'next_attempt_at' => $now,
                    'lease_expires_at' => null,
                ];
            }

            $outbox->update($changes);

            return $outbox->fresh();
        });

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
        $mapping = ProductChannelMapping::query()->find($mappingId);

        return $mapping === null ? null : $this->request($mapping, $action, $queueTier);
    }

    public function dispatchDue(?int $limit = null): array
    {
        // Scheduler and immediate wake jobs share the same per-shop budget.
        // Serialize selection so two dispatchers cannot both spend the last slot.
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

    private function dispatchDueWithinLock(?int $limit): array
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return ['claimed' => 0, 'reaped' => 0, 'revived' => 0, 'byChannel' => []];
        }

        $limit = max(1, $limit ?? (int) config('channel.stock_sync_dispatch_claim_limit', 50));
        $now = now();
        $reaped = $this->reapExpiredLeases($now);
        $revived = $this->reviveStrandedPendingDeliveries($now);
        $window = max(1, (int) config('channel.stock_sync_dispatch_window_seconds', 50));
        $leaseSeconds = max(60, (int) config('channel.stock_sync_lease_seconds', 600));
        $perShopSlots = [];
        $maxInFlightPerShop = max(1, (int) config('channel.stock_sync_max_inflight_per_shop', 1));
        $inFlightByShop = ChannelStockSyncOutbox::query()
            ->where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)
            ->where('lease_expires_at', '>', $now)
            ->select('channel_shop_id', DB::raw('count(*) as total'))
            ->groupBy('channel_shop_id')
            ->pluck('total', 'channel_shop_id')
            ->map(static fn ($total): int => (int) $total)
            ->all();
        $claimed = 0;
        $byChannel = [];
        $saturatedShops = array_keys(array_filter(
            $inFlightByShop,
            static fn (int $count): bool => $count >= $maxInFlightPerShop,
        ));

        $candidates = ChannelStockSyncOutbox::query()
            ->join('channel_shops', 'channel_shops.id', '=', 'channel_stock_sync_outbox.channel_shop_id')
            ->join('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->where('channel_stock_sync_outbox.status', ChannelStockSyncOutbox::STATUS_PENDING)
            ->whereNotIn('channel_stock_sync_outbox.channel_shop_id', $saturatedShops)
            ->whereColumn('channel_stock_sync_outbox.requested_version', '>', 'channel_stock_sync_outbox.dispatched_version')
            ->where(function ($query) use ($now): void {
                $query->whereNull('channel_stock_sync_outbox.next_attempt_at')
                    ->orWhere('channel_stock_sync_outbox.next_attempt_at', '<=', $now);
            })
            ->orderByRaw("CASE channel_stock_sync_outbox.queue_tier WHEN 'critical' THEN 0 ELSE 1 END")
            ->orderBy('channel_stock_sync_outbox.next_attempt_at')
            ->orderBy('channel_stock_sync_outbox.updated_at')
            ->limit($limit * 3)
            ->get([
                'channel_stock_sync_outbox.*',
                'channels.code as channel_code',
            ]);

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
            $updated = ChannelStockSyncOutbox::query()
                ->whereKey($outbox->id)
                ->where('status', ChannelStockSyncOutbox::STATUS_PENDING)
                ->where('requested_version', $version)
                ->whereColumn('requested_version', '>', 'dispatched_version')
                ->update([
                    'status' => ChannelStockSyncOutbox::STATUS_DISPATCHING,
                    'dispatched_version' => $version,
                    'dispatched_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
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
                $this->defer($outbox->id, $version, 'Gagal menaruh pekerjaan ke antrean: '.$exception->getMessage(), 30);
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

    public function shouldExecute(string $outboxId, int $version): bool
    {
        return DB::transaction(function () use ($outboxId, $version): bool {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null
                || $outbox->status !== ChannelStockSyncOutbox::STATUS_DISPATCHING
                || $outbox->dispatched_version !== $version) {
                return false;
            }

            if ($outbox->requested_version !== $version) {
                $this->makePending($outbox, now());

                return false;
            }

            $maxAttempts = max(1, (int) config('channel.stock_sync_max_attempts', 12));
            if ($outbox->attempt_count >= $maxAttempts) {
                $outbox->update([
                    'status' => ChannelStockSyncOutbox::STATUS_FAILED,
                    'completed_at' => now(),
                    'lease_expires_at' => null,
                    'last_error' => 'Batas percobaan API tercapai. Tidak ada percobaan baru yang dikirim.',
                ]);

                return false;
            }

            $outbox->increment('attempt_count');

            return true;
        });
    }

    public function succeed(string $outboxId, int $version): void
    {
        DB::transaction(function () use ($outboxId, $version): void {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null || $outbox->dispatched_version !== $version) {
                return;
            }

            if ($outbox->requested_version !== $version) {
                $outbox->update(['attempt_count' => 0]);
                $this->makePending($outbox, now());

                return;
            }

            $outbox->update([
                'sync_stock' => false,
                'sync_price' => false,
                'status' => ChannelStockSyncOutbox::STATUS_SUCCEEDED,
                'completed_version' => $version,
                'attempt_count' => 0,
                'completed_at' => now(),
                'next_attempt_at' => null,
                'lease_expires_at' => null,
                'last_error' => null,
            ]);
        });

        $this->wakeDispatcher();
    }

    public function defer(string $outboxId, int $version, string $reason, int $delaySeconds): void
    {
        DB::transaction(function () use ($outboxId, $version, $reason, $delaySeconds): void {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null || $outbox->dispatched_version !== $version) {
                return;
            }

            if ($outbox->requested_version !== $version) {
                $this->makePending($outbox, now());

                return;
            }

            $maxAttempts = max(1, (int) config('channel.stock_sync_max_attempts', 12));
            if ($outbox->attempt_count >= $maxAttempts) {
                $outbox->update([
                    'status' => ChannelStockSyncOutbox::STATUS_FAILED,
                    'completed_at' => now(),
                    'lease_expires_at' => null,
                    'last_error' => $reason,
                ]);

                return;
            }

            $outbox->update([
                'status' => ChannelStockSyncOutbox::STATUS_PENDING,
                'next_attempt_at' => now()->addSeconds(max(1, $delaySeconds)),
                'lease_expires_at' => null,
                'last_error' => $reason,
            ]);
        });

        if ($delaySeconds <= 30) {
            $this->wakeDispatcher();
        }
    }

    public function fail(string $outboxId, int $version, string $reason): void
    {
        DB::transaction(function () use ($outboxId, $version, $reason): void {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null || $outbox->dispatched_version !== $version) {
                return;
            }

            if ($outbox->requested_version !== $version) {
                $this->makePending($outbox, now());

                return;
            }

            $outbox->update([
                'status' => ChannelStockSyncOutbox::STATUS_FAILED,
                'completed_at' => now(),
                'lease_expires_at' => null,
                'last_error' => $reason,
            ]);
        });

        $this->wakeDispatcher();
    }

    public function skip(string $outboxId, int $version, string $reason): void
    {
        DB::transaction(function () use ($outboxId, $version, $reason): void {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null || $outbox->dispatched_version !== $version) {
                return;
            }

            if ($outbox->requested_version !== $version) {
                $this->makePending($outbox, now());

                return;
            }

            $outbox->update([
                'status' => ChannelStockSyncOutbox::STATUS_SKIPPED,
                'completed_at' => now(),
                'lease_expires_at' => null,
                'last_error' => $reason,
            ]);
        });

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
        return (int) ChannelStockSyncOutbox::query()
            ->whereKey($outboxId)
            ->value('attempt_count');
    }

    public function expiredDispatchingCount(?Carbon $now = null): int
    {
        $now ??= now();

        return ChannelStockSyncOutbox::query()
            ->where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->count();
    }

    public function reapExpiredLeases(?Carbon $now = null): int
    {
        $now ??= now();

        return ChannelStockSyncOutbox::query()
            ->where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->update([
                'status' => ChannelStockSyncOutbox::STATUS_PENDING,

                'requested_version' => DB::raw('requested_version + 1'),
                'next_attempt_at' => $now,
                'lease_expires_at' => null,
                'last_error' => 'Lease pengiriman sebelumnya kedaluwarsa; dijadwalkan ulang dengan nilai stok terbaru.',
                'updated_at' => $now,
            ]);
    }

    private function reviveStrandedPendingDeliveries(Carbon $now): int
    {
        return ChannelStockSyncOutbox::query()
            ->where('status', ChannelStockSyncOutbox::STATUS_PENDING)
            ->whereColumn('requested_version', '<=', 'dispatched_version')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->update([

                'requested_version' => DB::raw('dispatched_version + 1'),
                'next_attempt_at' => $now,
                'lease_expires_at' => null,
                'last_error' => 'Pengiriman lama tidak mendapat konfirmasi; dibuat versi baru dengan nilai stok terbaru.',
                'updated_at' => $now,
            ]);
    }

    private function makePending(ChannelStockSyncOutbox $outbox, Carbon $now): void
    {
        $outbox->update([
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'next_attempt_at' => $now,
            'lease_expires_at' => null,
            'last_error' => null,
        ]);

        $this->wakeDispatcher();
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

    private function higherPriorityTier(string $current, string $requested): string
    {
        return $current === 'critical' || $requested === 'critical' ? 'critical' : 'bulk';
    }
}
