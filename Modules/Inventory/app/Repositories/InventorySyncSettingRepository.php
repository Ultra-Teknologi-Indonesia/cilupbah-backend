<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class InventorySyncSettingRepository
{
    public function paginateMatrix(int $perPage = 20, ?Request $request = null): LengthAwarePaginator
    {
        $request ??= request();
        $perPage = min(max($perPage, 1), 100);

        $request = $this->normalizeLegacyFilters($request);

        return QueryBuilder::for(ProductVariant::class, $request)
            ->whereHas('product', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->with([
                'product:id,name,is_bundle',
                'options.attribute:id,name',
                'media:id,product_id,variant_id,url,is_primary,sort_order',
                'product.media:id,product_id,variant_id,url,is_primary,sort_order',
                'channelMappings' => fn ($query) => $query->with([
                    'channelMapping:id,product_id,channel_shop_id,sync_status,error_message,last_synced_at',
                    'channelMapping.stockSyncOutbox',
                ]),
            ])
            ->allowedSearch(
                'sku',
                'product.name',
                'product.sku',
            )
            ->allowedFilters(...[
                AllowedFilter::callback('channel_code', function (Builder $query, mixed $value): void {
                    $query->whereHas(
                        'channelMappings.channelMapping.channelShop.channel',
                        fn (Builder $channel) => $channel->where('code', (string) $value),
                    );
                }),
                AllowedFilter::callback('channel_shop_id', function (Builder $query, mixed $value): void {
                    $query->whereHas(
                        'channelMappings.channelMapping',
                        fn (Builder $mapping) => $mapping->where('channel_shop_id', (string) $value),
                    );
                }),
                AllowedFilter::callback('sync_status', function (Builder $query, mixed $value): void {
                    $query->whereHas(
                        'channelMappings.channelMapping',
                        fn (Builder $mapping) => $mapping->where('sync_status', (string) $value),
                    );
                }),
                AllowedFilter::callback('stock_sync_status', function (Builder $query, mixed $value): void {
                    $query->whereHas(
                        'channelMappings.channelMapping.stockSyncOutbox',
                        fn (Builder $outbox) => $outbox->where('status', (string) $value),
                    );
                }),
                AllowedFilter::callback('sync_enabled', function (Builder $query, mixed $value): void {
                    $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                    if ($enabled === null) {
                        return;
                    }

                    $query->whereHas(
                        'channelMappings',
                        fn (Builder $mapping) => $mapping->where('sync_enabled', $enabled),
                    );
                }),
                AllowedFilter::callback('is_bundle', function (Builder $query, mixed $value): void {
                    $isBundle = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                    if ($isBundle === null) {
                        return;
                    }

                    $query->whereHas('product', fn (Builder $product) => $product->where('is_bundle', $isBundle));
                }),
                AllowedFilter::callback('has_listing', function (Builder $query, mixed $value): void {
                    $hasListing = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                    if ($hasListing === true) {
                        $query->whereHas('channelMappings.channelMapping');
                    } elseif ($hasListing === false) {
                        $query->whereDoesntHave('channelMappings.channelMapping');
                    }
                }),
            ])
            ->allowedSorts(...[
                AllowedSort::field('sku', 'product_variants.sku'),
                AllowedSort::field('created_at', 'product_variants.created_at'),
                AllowedSort::field('updated_at', 'product_variants.updated_at'),
            ])
            ->defaultSort('sku')
            ->paginate($perPage)
            ->appends($request->query());
    }

    public function paginateHistory(
        string $productId,
        string $channelShopId,
        int $perPage = 10,
        ?Request $request = null,
    ): LengthAwarePaginator {
        $request ??= request();
        $perPage = min(max($perPage, 1), 25);

        return QueryBuilder::for(ProductSyncLog::class, $request)
            ->where('product_id', $productId)
            ->where('channel_shop_id', $channelShopId)
            ->where('action', ProductSyncLog::ACTION_SYNC_STOCK)
            ->with([
                'product:id,name',
                'channelShop:id,channel_id,shop_name',
                'channelShop.channel:id,code,name',
            ])
            ->allowedFilters(...[
                AllowedFilter::exact('status'),
            ])
            ->allowedSorts(...[
                AllowedSort::field('created_at', 'product_sync_logs.created_at'),
                AllowedSort::field('status', 'product_sync_logs.status'),
            ])
            ->defaultSort('-created_at')
            ->paginate($perPage)
            ->appends($request->query());
    }

    private function normalizeLegacyFilters(Request $request): Request
    {
        $normalized = Request::createFrom($request);
        $filters = is_array($normalized->query('filter')) ? $normalized->query('filter') : [];

        foreach (['channel_code', 'channel_shop_id'] as $key) {
            if (! array_key_exists($key, $filters) && $normalized->query($key) !== null) {
                $filters[$key] = $normalized->query($key);
            }
        }

        $normalized->query->set('filter', $filters);

        return $normalized;
    }
}
