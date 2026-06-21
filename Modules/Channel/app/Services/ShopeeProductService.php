<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Product\Models\ProductSyncLog;
use Ramsey\Uuid\Uuid;

class ShopeeProductService
{
    public function __construct(
        protected ShopeeClient $client,
        protected ChannelShopRepository $shopRepository,
        protected ShopeeAuthService $authService,
        protected ChannelProductRepository $productRepository,
    ) {}

    public function syncCategoryTree(string $shopId): int
    {
        $shop = $this->requireShop($shopId);
        $channelId = $this->channelId();

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_category', ['language' => 'id'], $token, $shop->shop_id));

        $list = $res['response']['category_list'] ?? [];

        $count = 0;
        $seen = [];

        foreach ($list as $node) {
            $extId = (string) ($node['category_id'] ?? '');
            if ($extId === '') {
                continue;
            }
            $seen[] = $extId;

            $values = [
                'parent_external_id' => (string) ($node['parent_category_id'] ?? '0'),
                'name' => (string) ($node['display_category_name'] ?? $node['original_category_name'] ?? ''),
                'is_leaf' => ! (bool) ($node['has_children'] ?? false),
                'updated_at' => now(),
            ];

            $existing = DB::table('channel_categories')
                ->where('channel_id', $channelId)
                ->where('external_id', $extId)
                ->first();

            if ($existing) {
                DB::table('channel_categories')->where('id', $existing->id)->update($values);
            } else {
                DB::table('channel_categories')->insert($values + [
                    'id' => Uuid::uuid7()->toString(),
                    'channel_id' => $channelId,
                    'external_id' => $extId,
                    'created_at' => now(),
                ]);
            }
            $count++;
        }

        $this->sweepDeprecated($channelId, $seen);

        return $count;
    }

    public function syncCategoryAttributes(string $shopId, string $categoryExtId): int
    {
        $shop = $this->requireShop($shopId);
        $channelId = $this->channelId();

        $channelCategory = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->where('external_id', $categoryExtId)
            ->first();

        if (! $channelCategory) {
            throw new \Exception("Kategori Shopee {$categoryExtId} belum disinkronkan (jalankan sinkron kategori dulu).");
        }

        $params = ['category_id' => (int) $categoryExtId, 'language' => 'id'];
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_attributes', $params, $token, $shop->shop_id));

        $attributes = $res['response']['attribute_list'] ?? [];
        $count = 0;

        foreach ($attributes as $attr) {
            $extId = (string) ($attr['attribute_id'] ?? '');
            if ($extId === '') {
                continue;
            }

            $inputType = strtolower((string) ($attr['input_validation_type'] ?? ''));

            $values = [
                'name' => (string) ($attr['original_attribute_name'] ?? $attr['display_attribute_name'] ?? ''),
                'is_required' => (bool) ($attr['is_mandatory'] ?? false),
                'is_multiple' => in_array($inputType, ['multiple_select', 'multiple_selection_combo_box'], true)
                    || (strtolower((string) ($attr['format_type'] ?? '')) === 'qualitative' && ($attr['is_multiple'] ?? false)),
                'is_sale_prop' => false, 
                'updated_at' => now(),
            ];

            $existing = DB::table('channel_attributes')
                ->where('channel_category_id', $channelCategory->id)
                ->where('external_id', $extId)
                ->first();

            if ($existing) {
                DB::table('channel_attributes')->where('id', $existing->id)->update($values);
                $channelAttributeId = $existing->id;
            } else {
                $channelAttributeId = Uuid::uuid7()->toString();
                DB::table('channel_attributes')->insert($values + [
                    'id' => $channelAttributeId,
                    'channel_category_id' => $channelCategory->id,
                    'external_id' => $extId,
                    'created_at' => now(),
                ]);
            }
            $count++;

            foreach ($attr['attribute_value_list'] ?? [] as $opt) {
                $optExtId = (string) ($opt['value_id'] ?? '');
                if ($optExtId === '') {
                    continue;
                }

                $optValues = [
                    'name' => (string) ($opt['original_value_name'] ?? $opt['display_value_name'] ?? $optExtId),
                    'updated_at' => now(),
                ];

                $existingOpt = DB::table('channel_attribute_options')
                    ->where('channel_attribute_id', $channelAttributeId)
                    ->where('external_id', $optExtId)
                    ->first();

                if ($existingOpt) {
                    DB::table('channel_attribute_options')->where('id', $existingOpt->id)->update($optValues);
                } else {
                    DB::table('channel_attribute_options')->insert($optValues + [
                        'id' => Uuid::uuid7()->toString(),
                        'channel_attribute_id' => $channelAttributeId,
                        'external_id' => $optExtId,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        return $count;
    }

    public function syncAllMappedCategoryAttributes(string $shopId): array
    {
        $channelId = $this->channelId();

        $leaves = DB::table('category_channel_mappings as m')
            ->join('channel_categories as c', 'c.id', '=', 'm.channel_category_id')
            ->where('c.channel_id', $channelId)
            ->distinct()
            ->pluck('c.external_id');

        $result = [];
        foreach ($leaves as $extId) {
            $result[(string) $extId] = $this->syncCategoryAttributes($shopId, (string) $extId);
        }

        return $result;
    }

    public function getModelList(string $shopId, string $itemId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_model_list', ['item_id' => (int) $itemId], $token, $shop->shop_id));

        return [
            'tier_variation' => $res['response']['tier_variation'] ?? [],
            'models' => $res['response']['model'] ?? [],
        ];
    }

    public function pullProducts(string $shopId): int
    {
        $shop = $this->requireShop($shopId);
        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper = app(ShopeeToInternalProductMapper::class);

        $count = 0;
        $offset = 0;
        $pageSize = 50;

        do {
            $list = $this->fetchItemList($shop, $offset, $pageSize);
            $itemIds = $this->extractItemIds($list);

            foreach (array_chunk($itemIds, 50) as $chunk) {
                foreach ($this->fetchBaseInfo($shop, $chunk) as $item) {
                    try {
                        $item = $this->hydrateModels($shop, $item);
                        if ($this->persistItem($shop, $shopId, $item, $mapper, $productService)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Shopee pull gagal item ' . ($item['item_id'] ?? '?') . ': ' . $e->getMessage());

                        ProductSyncLog::record([
                            'channel_shop_id' => $shop->id,
                            'action' => ProductSyncLog::ACTION_DOWNLOAD,
                            'status' => ProductSyncLog::STATUS_FAILED,
                            'payload' => [
                                'external_product_id' => $item['item_id'] ?? null,
                                'title' => $item['item_name'] ?? null,
                            ],
                            'error_message' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $hasNext = (bool) ($list['has_next_page'] ?? false);
            $offset = (int) ($list['next_offset'] ?? ($offset + $pageSize));
        } while ($hasNext);

        return $count;
    }

    public function pullProductById(string $shopId, string $externalProductId): bool
    {
        $shop = $this->requireShop($shopId);
        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper = app(ShopeeToInternalProductMapper::class);

        $item = $this->fetchBaseInfo($shop, [$externalProductId])[0] ?? null;
        if (! $item) {
            return false;
        }

        $item = $this->hydrateModels($shop, $item);

        if (! $this->persistItem($shop, $shopId, $item, $mapper, $productService)) {
            return false;
        }

        ProductSyncLog::record([
            'channel_shop_id' => $shop->id,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
            'response' => ['external_product_id' => (string) ($item['item_id'] ?? $externalProductId)],
        ]);

        return true;
    }

    public function searchProducts(string $shopId, string $query): array
    {
        $shop = $this->requireShop($shopId);
        $needle = trim(mb_strtolower($query));

        $results = [];
        $offset = 0;
        $pageSize = 50;
        $pages = 0;

        do {
            $list = $this->fetchItemList($shop, $offset, $pageSize);
            $itemIds = $this->extractItemIds($list);

            foreach ($this->fetchBaseInfo($shop, $itemIds) as $item) {
                $name = (string) ($item['item_name'] ?? '');
                $sellerSku = $item['item_sku'] ?? null;

                if ($needle !== '' && ! str_contains(mb_strtolower($name . ' ' . (string) $sellerSku), $needle)) {
                    continue;
                }

                $results[] = [
                    'external_product_id' => (string) ($item['item_id'] ?? ''),
                    'name' => $name,
                    'seller_sku' => $sellerSku,
                    'image' => $item['image']['image_url_list'][0] ?? null,
                    'shop_id' => $shopId,
                    'shop_name' => $shop->shop_name ?? null,
                    'channel_code' => 'shopee',
                ];
            }

            $hasNext = (bool) ($list['has_next_page'] ?? false);
            $offset = (int) ($list['next_offset'] ?? ($offset + $pageSize));
            $pages++;
        } while ($hasNext && $pages < 5 && count($results) < 200);

        return $results;
    }

    protected function fetchItemList(object $shop, int $offset, int $pageSize): array
    {
        return $this->fetchItemListByStatus($shop, $offset, $pageSize, 'NORMAL');
    }

    protected function fetchBaseInfo(object $shop, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_item_base_info', [
            'item_id_list' => implode(',', $itemIds),
            'need_complete_description' => true,
        ], $token, $shop->shop_id));

        return $res['response']['item_list'] ?? [];
    }

    protected function hydrateModels(object $shop, array $item): array
    {
        if (empty($item['has_model'])) {
            return $item;
        }

        $itemId = (string) ($item['item_id'] ?? '');
        if ($itemId === '') {
            return $item;
        }

        try {
            $modelList = $this->getModelList($shop->shop_id, $itemId);
            $item['model_list'] = $modelList['models'] ?? [];

            $item['tier_variation'] = $modelList['tier_variation'] ?? [];
        } catch (\Throwable $e) {
            Log::warning("Shopee get_model_list gagal item {$itemId}: " . $e->getMessage());
        }

        return $item;
    }

    protected function persistItem(object $shop, string $shopId, array $item, ShopeeToInternalProductMapper $mapper, $productService): bool
    {
        $internalData = app(ChannelAssetImporter::class)->import($mapper->map($item, $shopId));
        $insertedId = $productService->upsertFromChannel($internalData);
        if (! $insertedId) {
            return false;
        }

        $pcmId = $this->productRepository->upsertChannelMapping(
            (string) $insertedId,
            $shopId,
            (string) ($item['item_id'] ?? ''),
            'synced',
            null
        );

        $models = $item['model_list'] ?? [];

        if (empty($models)) {

            $sku = 'SHP-' . ($item['item_id'] ?? '');
            $variant = $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku);
            if ($variant) {
                $this->productRepository->upsertVariantChannelMapping(
                    $pcmId,
                    $variant->id,
                    null,
                    $item['item_sku'] ?? null,
                    $item['price_info'][0]['current_price'] ?? null
                );
            }

            return true;
        }

        foreach ($models as $model) {
            $sku = ! empty($model['model_sku'])
                ? $model['model_sku']
                : ('SHP-' . ($model['model_id'] ?? ''));

            $variant = $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku);
            if (! $variant) {
                continue;
            }

            $this->productRepository->upsertVariantChannelMapping(
                $pcmId,
                $variant->id,
                isset($model['model_id']) ? (string) $model['model_id'] : null,
                $model['model_sku'] ?? null,
                $model['price_info'][0]['current_price'] ?? $model['original_price'] ?? null
            );
        }

        return true;
    }

    protected function extractItemIds(array $list): array
    {
        return array_values(array_filter(array_map(
            fn ($it) => (string) ($it['item_id'] ?? ''),
            $list['item'] ?? []
        )));
    }

    protected function sweepDeprecated(string $channelId, array $seen): void
    {
        if (empty($seen)) {
            return;
        }

        DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->whereNotIn('external_id', $seen)
            ->whereNull('deprecated_at')
            ->update(['deprecated_at' => now(), 'updated_at' => now()]);

        DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->whereIn('external_id', $seen)
            ->whereNotNull('deprecated_at')
            ->update(['deprecated_at' => null, 'updated_at' => now()]);
    }

    protected function channelId(): string
    {
        $channelId = DB::table('channels')->where('code', 'shopee')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel shopee belum ter-seed.');
        }

        return $channelId;
    }

    protected function callWithRefresh(object $shop, callable $fn): array
    {
        try {
            return $fn($shop->access_token);
        } catch (TokenExpiredException $e) {
            $this->authService->refreshStoreToken((string) $shop->id);
            $fresh = $this->shopRepository->findByShopId($shop->shop_id);

            return $fn($fresh->access_token);
        }
    }

    /**
     * @return array<string, array{status: string, reason: string|null}>
     */
    public function fetchProductStatuses(string $shopId): array
    {
        $shop = $this->requireShop($shopId);
        $statuses = [];

        foreach (['NORMAL', 'BANNED', 'DELETED', 'UNLIST'] as $itemStatus) {
            $offset = 0;
            $pageSize = 100;

            do {
                $list = $this->fetchItemListByStatus($shop, $offset, $pageSize, $itemStatus);
                $items = $list['item'] ?? [];

                foreach ($items as $item) {
                    $extId = (string) ($item['item_id'] ?? '');
                    if ($extId === '') {
                        continue;
                    }
                    $statuses[$extId] = [
                        'status' => strtolower($itemStatus),
                        'reason' => null,
                    ];
                }

                $hasNext = (bool) ($list['has_next_page'] ?? false);
                $offset = (int) ($list['next_offset'] ?? ($offset + $pageSize));
            } while ($hasNext);
        }

        return $statuses;
    }

    protected function fetchItemListByStatus(object $shop, int $offset, int $pageSize, string $itemStatus): array
    {
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_item_list', [
            'offset' => $offset,
            'page_size' => $pageSize,
            'item_status' => $itemStatus,
        ], $token, $shop->shop_id));

        return $res['response'] ?? [];
    }

    /**
     * @return array<string, array{success: bool, reason: string|null}>
     */
    public function boostItem(string $shopId, array $itemIds): array
    {
        $shop = $this->requireShop($shopId);
        $results = [];

        // Shopee allows max 5 items per boost call
        foreach (array_chunk($itemIds, 5) as $chunk) {
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/product/boost_item', [
                'item_id_list' => array_map('intval', $chunk),
            ], $token, $shop->shop_id));

            $failures = $res['response']['failures'] ?? [];
            foreach ($chunk as $itemId) {
                $failure = collect($failures)->firstWhere('item_id', (int) $itemId);
                $results[(string) $itemId] = [
                    'success' => $failure === null,
                    'reason' => $failure['failed_reason'] ?? null,
                ];
            }
        }

        return $results;
    }

    protected function requireShop(string $shopId): object
    {
        $shop = $this->shopRepository->findByShopId($shopId);

        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Shopee tidak terhubung: {$shopId}");
        }

        return $shop;
    }
}
