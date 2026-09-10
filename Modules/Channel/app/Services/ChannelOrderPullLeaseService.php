<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;

/**
 * Durable per-store lease for scheduled order pulls.
 *
 * This deliberately uses PostgreSQL, not the cache. The order inbox/database
 * remains available for safe recovery even if CoreDNS or Redis cache is down.
 */
final class ChannelOrderPullLeaseService
{
    public function acquire(ChannelShop $shop, int $seconds): ?string
    {
        $token = (string) Str::uuid();
        $now = now();

        $claimed = ChannelShop::query()
            ->whereKey($shop->id)
            ->where('is_active', true)
            ->where('order_sync_enabled', true)
            ->whereNull('disconnected_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('order_pull_locked_until')
                    ->orWhere('order_pull_locked_until', '<=', $now);
            })
            ->update([
                'order_pull_lease_token' => $token,
                'order_pull_locked_until' => $now->copy()->addSeconds($seconds),
                'updated_at' => $now,
            ]);

        return $claimed === 1 ? $token : null;
    }

    public function owns(ChannelShop $shop, string $token): bool
    {
        return hash_equals((string) $shop->order_pull_lease_token, $token)
            && $shop->order_pull_locked_until?->isFuture();
    }

    public function release(string $shopId, string $token): void
    {
        ChannelShop::query()
            ->whereKey($shopId)
            ->where('order_pull_lease_token', $token)
            ->update([
                'order_pull_lease_token' => null,
                'order_pull_locked_until' => null,
                'updated_at' => now(),
            ]);
    }
}
