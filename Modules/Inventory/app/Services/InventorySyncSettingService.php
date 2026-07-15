<?php

namespace Modules\Inventory\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;

class InventorySyncSettingService
{
    public function filtersFrom(array $input): array
    {
        return array_filter([
            'search'          => $input['search'] ?? null,
            'channel_code'    => $input['channel_code'] ?? null,
            'channel_shop_id' => $input['channel_shop_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function matrix(array $filters, int $perPage): LengthAwarePaginator
    {
        $shopIds = $this->scopedShopIds($filters);

        return ProductVariant::query()
            ->whereHas('product')
            ->with([
                'product:id,name,is_bundle',
                'options.attribute:id,name',
                'media',
                'product.media',
                'channelMappings' => fn ($q) => $q->with('channelMapping:id,channel_shop_id,sync_status'),
            ])
            ->when(! empty($filters['search']), function ($query) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('sku', 'ilike', $term)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', $term));
                });
            })
            ->when($shopIds !== null, function ($query) use ($shopIds) {
                $query->whereHas('channelMappings.channelMapping', fn ($q) => $q->whereIn('channel_shop_id', $shopIds));
            })
            ->orderBy('sku')
            ->paginate($perPage);
    }

    public function storesCatalog(array $filters): array
    {
        return ChannelShop::query()
            ->where('is_active', true)
            ->whereNull('disconnected_at')
            ->when(! empty($filters['channel_code']), fn ($q) => $q->whereHas('channel', fn ($c) => $c->where('code', $filters['channel_code'])))
            ->when(! empty($filters['channel_shop_id']), fn ($q) => $q->where('id', $filters['channel_shop_id']))
            ->with('channel:id,code')
            ->orderBy('shop_name')
            ->get()
            ->map(fn (ChannelShop $shop) => [
                'channel_shop_id' => $shop->id,
                'shop_name'       => $shop->shop_name,
                'channel_code'    => $shop->channel?->code,
            ])
            ->all();
    }

    public function toggle(array $items): int
    {
        $affected = 0;
        $resyncTargets = [];

        DB::transaction(function () use ($items, &$affected, &$resyncTargets) {
            foreach ($items as $item) {
                $mapping = ProductVariantChannelMapping::query()
                    ->where('variant_id', $item['variant_id'])
                    ->whereHas('channelMapping', fn ($q) => $q->where('channel_shop_id', $item['channel_shop_id']))
                    ->with('channelMapping:id,product_id,channel_shop_id')
                    ->first();

                if ($mapping === null || (bool) $mapping->sync_enabled === $item['sync_enabled']) {
                    continue;
                }

                $mapping->update(['sync_enabled' => $item['sync_enabled']]);
                $affected++;

                if ($item['sync_enabled'] && $mapping->channelMapping) {
                    $resyncTargets[$mapping->channelMapping->product_id][$item['channel_shop_id']] = true;
                }
            }
        });

        $this->dispatchResync($resyncTargets);

        return $affected;
    }

    public function bulkToggle(bool $syncEnabled, array $filters, ?string $channelShopId = null): int
    {
        $shopIds = $channelShopId !== null ? [$channelShopId] : $this->scopedShopIds($filters);

        $query = ProductVariantChannelMapping::query()
            ->where('sync_enabled', ! $syncEnabled)
            ->whereHas('channelMapping', function ($q) use ($shopIds) {
                if ($shopIds !== null) {
                    $q->whereIn('channel_shop_id', $shopIds);
                }
            })
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->whereHas('variant', function ($v) use ($term) {
                    $v->where('sku', 'ilike', $term)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', $term));
                });
            });

        $resyncTargets = [];

        if ($syncEnabled) {
            $query->clone()
                ->with('channelMapping:id,product_id,channel_shop_id')
                ->chunkById(500, function ($mappings) use (&$resyncTargets) {
                    foreach ($mappings as $mapping) {
                        if ($mapping->channelMapping) {
                            $resyncTargets[$mapping->channelMapping->product_id][$mapping->channelMapping->channel_shop_id] = true;
                        }
                    }
                });
        }

        $affected = $query->update(['sync_enabled' => $syncEnabled]);

        $this->dispatchResync($resyncTargets);

        return $affected;
    }

    private function dispatchResync(array $resyncTargets): void
    {
        foreach ($resyncTargets as $productId => $shops) {
            foreach (array_keys($shops) as $shopId) {
                SyncProductToChannelJob::dispatch($productId, $shopId, 'sync_price_stock');
            }
        }
    }

    private function scopedShopIds(array $filters): ?array
    {
        if (! empty($filters['channel_shop_id'])) {
            return [$filters['channel_shop_id']];
        }

        if (! empty($filters['channel_code'])) {
            return ChannelShop::query()
                ->where('is_active', true)
                ->whereNull('disconnected_at')
                ->whereHas('channel', fn ($c) => $c->where('code', $filters['channel_code']))
                ->pluck('id')
                ->all();
        }

        return null;
    }
}
