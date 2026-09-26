<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\ShippingLabelCacheArtifact;

final class ShippingLabelCacheRepository
{
    public function attachCache(string $orderId, array $cache, ?array $rawData): array
    {
        return DB::transaction(function () use ($orderId, $cache, $rawData): array {
            $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $metadata = array_replace(is_array($order->shipping_label_raw_data) ? $order->shipping_label_raw_data : [], $rawData ?? []);
            $metadata['cache'] = $cache;
            $order->forceFill(['shipping_label_raw_data' => $metadata])->saveQuietly();

            return $metadata;
        });
    }

    public function find(string $id): ?ShippingLabelCacheArtifact
    {
        return ShippingLabelCacheArtifact::find($id);
    }

    public function byPath(string $path): ?ShippingLabelCacheArtifact
    {
        return ShippingLabelCacheArtifact::where('path', $path)->first();
    }

    public function stage(array $attributes): ShippingLabelCacheArtifact
    {
        return ShippingLabelCacheArtifact::firstOrCreate(['path' => $attributes['path']], $attributes);
    }

    public function update(ShippingLabelCacheArtifact $artifact, array $attributes): void
    {
        $artifact->forceFill($attributes)->save();
    }

    public function due(int $limit): Collection
    {
        return ShippingLabelCacheArtifact::whereNull('archived_at')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit($limit)->get();
    }

    public function health(): array
    {
        $pending = ShippingLabelCacheArtifact::whereNull('archived_at')->selectRaw('COUNT(*) AS total, MIN(created_at) AS oldest_at, COALESCE(SUM(bytes), 0) AS bytes')->first();

        return ['pending' => (int) $pending->total, 'pending_bytes' => (int) $pending->bytes,
            'oldest_pending_at' => $pending->oldest_at,
            'with_errors' => ShippingLabelCacheArtifact::whereNull('archived_at')->whereNotNull('last_error')->count()];
    }

    public function expiredLocal(int $limit): Collection
    {
        return ShippingLabelCacheArtifact::whereNull('local_deleted_at')
            ->where('archived_at', '<=', now()->subHours((int) config('bulk-labels.cache_local_hours', 24)))
            ->orderBy('archived_at')->limit($limit)->get();
    }
}
