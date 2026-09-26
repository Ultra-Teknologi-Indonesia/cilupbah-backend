<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;

final class BulkShippingLabelRequestRepository
{
    public function eachPendingChunk(string $batchId, Closure $callback): void
    {
        BulkShippingLabelItem::query()->where('batch_id', $batchId)
            ->where('status', BulkShippingLabelItem::STATUS_PENDING)
            ->with('order:id,channel_shop_id')
            ->chunkById(100, $callback);
    }

    public function findBatch(string $batchId): ?BulkShippingLabelBatch
    {
        return BulkShippingLabelBatch::find($batchId);
    }

    public function printableItems(string $batchId, ?array $warehouseIds, int $limit): Collection
    {
        return BulkShippingLabelItem::query()->where('batch_id', $batchId)
            ->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->whereHas('order', function ($query) use ($warehouseIds): void {
                $query->where('is_canceled', false)->where('status', '!=', 'cancelled')
                    ->whereNull('cancel_requested_at')
                    ->where(fn ($q) => $q->whereNull('channel_cancel_status')->orWhere('channel_cancel_status', '!=', 'accepted'));
                if ($warehouseIds !== null) {
                    $query->whereIn('location_id', $warehouseIds);
                }
            })
            ->orderBy('created_at')->orderBy('id')->limit($limit)->get();
    }

    public function reusableSnapshot(string $userId, string $key): ?BulkShippingLabelBatch
    {
        return BulkShippingLabelBatch::query()->where('user_id', $userId)->where('request_key', $key)
            ->whereIn('status', [BulkShippingLabelBatch::STATUS_PROCESSING, BulkShippingLabelBatch::STATUS_READY])
            ->whereNull('file_purged_at')->latest()->first();
    }

    public function hasInaccessibleCompletedItems(string $batchId, array $warehouseIds): bool
    {
        return BulkShippingLabelItem::query()->where('batch_id', $batchId)
            ->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->whereDoesntHave('order', fn ($query) => $query->whereIn('location_id', $warehouseIds))
            ->exists();
    }

    public function subscribeShopeePreparation(BulkShippingLabelItem $item): bool
    {
        return BulkShippingLabelItem::query()->whereKey($item->id)
            ->whereIn('status', [BulkShippingLabelItem::STATUS_PENDING, BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP])
            ->update(['status' => BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP, 'updated_at' => now()]) === 1;
    }

    public function releaseShopeePreparation(BulkShippingLabelItem $item): bool
    {
        return BulkShippingLabelItem::query()->whereKey($item->id)
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP)
            ->update(['status' => BulkShippingLabelItem::STATUS_PENDING, 'updated_at' => now()]) === 1;
    }

    public function shopeePreparationItems(string $batchId, array $itemIds): Collection
    {
        return BulkShippingLabelItem::query()
            ->where('batch_id', $batchId)
            ->whereIn('id', $itemIds)
            ->where('channel', 'shopee')
            ->whereIn('status', [BulkShippingLabelItem::STATUS_PENDING, BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP])
            ->whereHas('batch', fn ($query) => $query->where('status', BulkShippingLabelBatch::STATUS_PROCESSING))
            ->get();
    }

    public function firstOrCreateActive(string $userId, string $key, Closure $create): array
    {
        return DB::transaction(function () use ($userId, $key, $create): array {

            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = BulkShippingLabelBatch::query()
                ->where('user_id', $userId)
                ->where('request_key', $key)
                ->where('status', BulkShippingLabelBatch::STATUS_PROCESSING)
                ->first();

            return $existing ? [$existing, false] : [$create(), true];
        });
    }
}
