<?php

namespace Modules\Inventory\Services;

use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockSyncOutboxService;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Inventory\Repositories\InventorySyncSettingRepository;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Support\BundleStock;

class InventorySyncSettingService
{
    public function __construct(
        protected InventorySyncSettingRepository $repository,
    ) {}

    public function filtersFrom(array $input): array
    {
        $queryFilters = is_array($input['filter'] ?? null) ? $input['filter'] : [];

        return array_filter([
            'search' => $input['search'] ?? null,
            'channel_code' => $queryFilters['channel_code'] ?? $input['channel_code'] ?? null,
            'channel_shop_id' => $queryFilters['channel_shop_id'] ?? $input['channel_shop_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function matrix(array $filters, int $perPage, ?Request $request = null): LengthAwarePaginator
    {
        $paginator = $this->repository->paginateMatrix($perPage, $request);
        $this->attachInternalStock($paginator);

        return $paginator;
    }

    public function history(
        ProductChannelMapping $mapping,
        int $perPage,
        ?Request $request = null,
    ): LengthAwarePaginator {
        return $this->repository->paginateHistory(
            (string) $mapping->product_id,
            (string) $mapping->channel_shop_id,
            $perPage,
            $request,
        );
    }

    public function retryMapping(string $mappingId): array
    {
        $mapping = ProductChannelMapping::query()
            ->with(['product:id,is_active', 'channelShop:id,is_active,disconnected_at', 'variantMappings'])
            ->findOrFail($mappingId);

        if (! $mapping->product?->is_active) {
            throw new DomainException('Produk sudah tidak aktif.');
        }

        if (! $mapping->channelShop?->is_active || $mapping->channelShop?->disconnected_at !== null) {
            throw new DomainException('Toko channel tidak aktif atau sudah terputus.');
        }

        if (blank($mapping->external_product_id)) {
            throw new DomainException('Listing belum memiliki ID produk di channel.');
        }

        if ($mapping->variantMappings->isNotEmpty()
            && ChannelVariantMappingResolver::hasEnabledMappings($mapping)
            && ChannelVariantMappingResolver::enabledForListing($mapping)->isEmpty()) {
            throw new DomainException('Tidak ada varian master aktif yang dapat disinkronkan.');
        }

        $outbox = app(ChannelStockSyncOutboxService::class)->request(
            $mapping,
            'sync_stock',
            'critical',
            true,
        );

        return [
            'mapping_id' => $mapping->id,
            'outbox_id' => $outbox->id,
            'status' => $outbox->status,
            'requested_version' => $outbox->requested_version,
        ];
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
                'shop_name' => $shop->shop_name,
                'channel_code' => $shop->channel?->code,
            ])
            ->all();
    }

    public function toggle(array $items): int
    {
        $affected = 0;
        $resyncTargets = [];

        $rows = collect($items);

        $mappings = ProductVariantChannelMapping::query()
            ->whereIn('variant_id', $rows->pluck('variant_id')->filter()->unique()->all())
            ->whereHas(
                'channelMapping',
                fn ($q) => $q->whereIn('channel_shop_id', $rows->pluck('channel_shop_id')->filter()->unique()->all())
            )
            ->with('channelMapping:id,product_id,channel_shop_id')
            ->get()
            ->keyBy(fn ($m) => $m->variant_id.'|'.$m->channelMapping?->channel_shop_id);

        $toEnable = [];
        $toDisable = [];

        foreach ($items as $item) {
            $mapping = $mappings[$item['variant_id'].'|'.$item['channel_shop_id']] ?? null;

            if ($mapping === null || (bool) $mapping->sync_enabled === $item['sync_enabled']) {
                continue;
            }

            if ($item['sync_enabled']) {
                $toEnable[] = $mapping->id;
            } else {
                $toDisable[] = $mapping->id;
            }

            $affected++;

            if ($item['sync_enabled'] && $mapping->channelMapping) {
                $resyncTargets[$mapping->channelMapping->product_id][$item['channel_shop_id']] = true;
            }
        }

        DB::transaction(function () use ($toEnable, $toDisable) {
            if (! empty($toEnable)) {
                ProductVariantChannelMapping::whereIn('id', $toEnable)->update(['sync_enabled' => true]);
            }

            if (! empty($toDisable)) {
                ProductVariantChannelMapping::whereIn('id', $toDisable)->update(['sync_enabled' => false]);
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
                $term = '%'.$filters['search'].'%';
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
                SyncProductToChannelJob::dispatch($productId, $shopId, 'sync_stock');
            }
        }
    }

    private function attachInternalStock(LengthAwarePaginator $paginator): void
    {
        $variants = $paginator->getCollection();
        $stockByVariant = StockSummary::forItems($variants->pluck('id')->map(fn ($id) => (string) $id)->all());

        foreach ($variants as $variant) {
            $variant->setAttribute('internal_stock', $stockByVariant[(string) $variant->id] ?? $this->emptyStock());
        }

        $bundles = $variants
            ->filter(fn (ProductVariant $variant): bool => (bool) $variant->product?->is_bundle)
            ->values();

        if ($bundles->isEmpty()) {
            return;
        }

        $bundles->load([
            'product.bundleItems.component:id,product_id',
            'product.bundleItems.component.inventories:id,item_id,location_id,bin_id,on_hand,on_order',
            'product.bundleItems.component.inventories.bin:id,location_id,is_inbound',
            'product.bundleItems.component.inventories.location:id,location_code,location_name',
        ]);

        foreach ($bundles as $variant) {
            $variant->setAttribute(
                'internal_stock',
                BundleStock::derive($variant->product) ?? $this->emptyStock(),
            );
        }
    }

    private function emptyStock(): array
    {
        return [
            'on_hand' => 0,
            'on_order' => 0,
            'available' => 0,
        ];
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
