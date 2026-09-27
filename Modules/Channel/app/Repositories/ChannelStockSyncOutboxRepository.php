<?php

declare(strict_types=1);

namespace Modules\Channel\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelStockSyncOutbox;
use Modules\Product\Models\ProductChannelMapping;

final class ChannelStockSyncOutboxRepository
{
    public const TRANSITION_IGNORED = 'ignored';

    public const TRANSITION_UPDATED = 'updated';

    public const TRANSITION_SUPERSEDED = 'superseded';

    public const TRANSITION_FAILED = 'failed';

    public function findMapping(string $mappingId): ?ProductChannelMapping
    {
        return ProductChannelMapping::query()->find($mappingId);
    }

    public function request(
        ProductChannelMapping $mapping,
        bool $syncStock,
        bool $syncPrice,
        string $queueTier,
        bool $resetAttempts,
        Carbon $now,
    ): ChannelStockSyncOutbox {
        return DB::transaction(function () use ($mapping, $syncStock, $syncPrice, $queueTier, $resetAttempts, $now): ChannelStockSyncOutbox {
            ProductChannelMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
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
                'queue_tier' => $outbox->queue_tier === 'critical' || $queueTier === 'critical' ? 'critical' : 'bulk',
                'requested_version' => $outbox->requested_version + 1,
                'last_error' => null,
                'updated_at' => $now,
            ];

            if (($resetAttempts || $outbox->status === ChannelStockSyncOutbox::STATUS_SUCCEEDED)
                && $outbox->status !== ChannelStockSyncOutbox::STATUS_DISPATCHING) {
                $changes += ['attempt_count' => 0, 'completed_at' => null];
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
    }

    public function inFlightByShop(Carbon $now): array
    {
        return ChannelStockSyncOutbox::query()
            ->where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)
            ->where('lease_expires_at', '>', $now)
            ->selectRaw('channel_shop_id, COUNT(*) AS total')
            ->groupBy('channel_shop_id')
            ->pluck('total', 'channel_shop_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    public function dueCandidates(Carbon $now, array $saturatedShopIds, int $limit): Collection
    {
        return ChannelStockSyncOutbox::query()
            ->join('channel_shops', 'channel_shops.id', '=', 'channel_stock_sync_outbox.channel_shop_id')
            ->join('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->where('channel_stock_sync_outbox.status', ChannelStockSyncOutbox::STATUS_PENDING)
            ->when($saturatedShopIds !== [], fn ($query) => $query->whereNotIn('channel_stock_sync_outbox.channel_shop_id', $saturatedShopIds))
            ->whereColumn('channel_stock_sync_outbox.requested_version', '>', 'channel_stock_sync_outbox.dispatched_version')
            ->where(function ($query) use ($now): void {
                $query->whereNull('channel_stock_sync_outbox.next_attempt_at')
                    ->orWhere('channel_stock_sync_outbox.next_attempt_at', '<=', $now);
            })
            ->orderByRaw("CASE channel_stock_sync_outbox.queue_tier WHEN 'critical' THEN 0 ELSE 1 END")
            ->orderBy('channel_stock_sync_outbox.next_attempt_at')
            ->orderBy('channel_stock_sync_outbox.updated_at')
            ->limit(max(1, $limit))
            ->get(['channel_stock_sync_outbox.*', 'channels.code AS channel_code']);
    }

    public function claim(string $outboxId, int $version, Carbon $now, int $leaseSeconds): bool
    {
        return ChannelStockSyncOutbox::query()
            ->whereKey($outboxId)
            ->where('status', ChannelStockSyncOutbox::STATUS_PENDING)
            ->where('requested_version', $version)
            ->whereColumn('requested_version', '>', 'dispatched_version')
            ->update([
                'status' => ChannelStockSyncOutbox::STATUS_DISPATCHING,
                'dispatched_version' => $version,
                'dispatched_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'updated_at' => $now,
            ]) === 1;
    }

    public function beginAttempt(string $outboxId, int $version, int $maxAttempts, Carbon $now): string
    {
        return DB::transaction(function () use ($outboxId, $version, $maxAttempts, $now): string {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null
                || $outbox->status !== ChannelStockSyncOutbox::STATUS_DISPATCHING
                || $outbox->dispatched_version !== $version) {
                return self::TRANSITION_IGNORED;
            }

            if ($outbox->requested_version !== $version) {
                $this->markPending($outbox, $now);

                return self::TRANSITION_SUPERSEDED;
            }

            if ($outbox->attempt_count >= $maxAttempts) {
                $outbox->update([
                    'status' => ChannelStockSyncOutbox::STATUS_FAILED,
                    'completed_at' => $now,
                    'lease_expires_at' => null,
                    'last_error' => 'Batas percobaan API tercapai. Tidak ada percobaan baru yang dikirim.',
                ]);

                return self::TRANSITION_FAILED;
            }

            $outbox->increment('attempt_count');

            return self::TRANSITION_UPDATED;
        });
    }

    public function markSucceeded(string $outboxId, int $version, Carbon $now): string
    {
        return $this->transition($outboxId, $version, $now, function (ChannelStockSyncOutbox $outbox) use ($version, $now): void {
            $outbox->update([
                'sync_stock' => false,
                'sync_price' => false,
                'status' => ChannelStockSyncOutbox::STATUS_SUCCEEDED,
                'completed_version' => $version,
                'attempt_count' => 0,
                'completed_at' => $now,
                'next_attempt_at' => null,
                'lease_expires_at' => null,
                'last_error' => null,
            ]);
        }, true);
    }

    public function markDeferred(
        string $outboxId,
        int $version,
        string $reason,
        int $delaySeconds,
        int $maxAttempts,
        Carbon $now,
    ): string {
        return $this->transition($outboxId, $version, $now, function (ChannelStockSyncOutbox $outbox) use ($reason, $delaySeconds, $maxAttempts, $now): void {
            if ($outbox->attempt_count >= $maxAttempts) {
                $outbox->update([
                    'status' => ChannelStockSyncOutbox::STATUS_FAILED,
                    'completed_at' => $now,
                    'lease_expires_at' => null,
                    'last_error' => $reason,
                ]);

                return;
            }

            $outbox->update([
                'status' => ChannelStockSyncOutbox::STATUS_PENDING,
                'next_attempt_at' => $now->copy()->addSeconds(max(1, $delaySeconds)),
                'lease_expires_at' => null,
                'last_error' => $reason,
            ]);
        });
    }

    public function markFailed(string $outboxId, int $version, string $reason, Carbon $now): string
    {
        return $this->transition($outboxId, $version, $now, static function (ChannelStockSyncOutbox $outbox) use ($reason, $now): void {
            $outbox->update([
                'status' => ChannelStockSyncOutbox::STATUS_FAILED,
                'completed_at' => $now,
                'lease_expires_at' => null,
                'last_error' => $reason,
            ]);
        });
    }

    public function markSkipped(string $outboxId, int $version, string $reason, Carbon $now): string
    {
        return $this->transition($outboxId, $version, $now, static function (ChannelStockSyncOutbox $outbox) use ($reason, $now): void {
            $outbox->update([
                'status' => ChannelStockSyncOutbox::STATUS_SKIPPED,
                'completed_at' => $now,
                'lease_expires_at' => null,
                'last_error' => $reason,
            ]);
        });
    }

    public function attemptCount(string $outboxId): int
    {
        return (int) ChannelStockSyncOutbox::query()->whereKey($outboxId)->value('attempt_count');
    }

    public function expiredDispatchingCount(Carbon $now): int
    {
        return ChannelStockSyncOutbox::query()
            ->where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->count();
    }

    public function reapExpiredLeases(Carbon $now): int
    {
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

    public function reviveStrandedPendingDeliveries(Carbon $now): int
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

    public function monitoringSummary(): Collection
    {
        return ChannelStockSyncOutbox::query()
            ->join('channel_shops', 'channel_shops.id', '=', 'channel_stock_sync_outbox.channel_shop_id')
            ->join('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->selectRaw('channels.code AS channel, channel_stock_sync_outbox.status, COUNT(*) AS total, MIN(channel_stock_sync_outbox.next_attempt_at) AS earliest_next_attempt')
            ->groupBy('channels.code', 'channel_stock_sync_outbox.status')
            ->orderBy('channels.code')
            ->orderBy('channel_stock_sync_outbox.status')
            ->get();
    }

    public function monitoringErrors(int $limit): Collection
    {
        return ChannelStockSyncOutbox::query()
            ->join('channel_shops', 'channel_shops.id', '=', 'channel_stock_sync_outbox.channel_shop_id')
            ->join('channels', 'channels.id', '=', 'channel_shops.channel_id')
            ->whereIn('channel_stock_sync_outbox.status', [ChannelStockSyncOutbox::STATUS_FAILED, ChannelStockSyncOutbox::STATUS_PENDING])
            ->whereNotNull('channel_stock_sync_outbox.last_error')
            ->where('channel_stock_sync_outbox.last_error', '!=', '')
            ->selectRaw('channels.code AS channel, channel_stock_sync_outbox.status, channel_stock_sync_outbox.last_error, COUNT(*) AS total, MAX(channel_stock_sync_outbox.updated_at) AS last_seen_at')
            ->groupBy('channels.code', 'channel_stock_sync_outbox.status', 'channel_stock_sync_outbox.last_error')
            ->orderByDesc('total')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    private function transition(
        string $outboxId,
        int $version,
        Carbon $now,
        callable $operation,
        bool $resetAttemptsWhenSuperseded = false,
    ): string {
        return DB::transaction(function () use ($outboxId, $version, $now, $operation, $resetAttemptsWhenSuperseded): string {
            $outbox = ChannelStockSyncOutbox::query()->lockForUpdate()->find($outboxId);

            if ($outbox === null || $outbox->dispatched_version !== $version) {
                return self::TRANSITION_IGNORED;
            }

            if ($outbox->requested_version !== $version) {
                if ($resetAttemptsWhenSuperseded) {
                    $outbox->update(['attempt_count' => 0]);
                }

                $this->markPending($outbox, $now);

                return self::TRANSITION_SUPERSEDED;
            }

            $operation($outbox);

            return self::TRANSITION_UPDATED;
        });
    }

    private function markPending(ChannelStockSyncOutbox $outbox, Carbon $now): void
    {
        $outbox->update([
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'next_attempt_at' => $now,
            'lease_expires_at' => null,
            'last_error' => null,
        ]);
    }
}
