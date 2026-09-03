<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\ChunkedDownloadable;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Support\ChannelModelLinker;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariantChannelMapping;
use Ramsey\Uuid\Uuid;

class ShopeeProductService implements ChunkedDownloadable
{
    public function __construct(
        protected ShopeeClient $client,
        protected ChannelShopRepository $shopRepository,
        protected ShopeeAuthService $authService,
        protected ChannelProductRepository $productRepository,
    ) {}

    public function pushProductListing(string $shopId, string $productId): array
    {
        $shop = $this->shopRepository->findConnectedByShopId($shopId);
        if (! $shop) {
            return ['ok' => false, 'code' => 404, 'message' => 'Toko Shopee tidak ditemukan / terputus'];
        }

        $product = $this->productRepository->findProductModelWithVariantsAndMedia($productId);
        if (! $product) {
            return ['ok' => false, 'code' => 404, 'message' => 'Produk tidak ditemukan'];
        }

        $issues = app(\Modules\Channel\Services\ChannelListingValidator::class)->validate($product, 'shopee');
        if (! empty($issues)) {
            return ['ok' => false, 'code' => 422, 'message' => 'Produk belum siap di-listing ke Shopee', 'errors' => ['issues' => $issues]];
        }

        $result = app(\Modules\Channel\Adapters\ShopeeAdapter::class)->pushProduct($product, $shop);

        if (! ($result['success'] ?? false)) {
            return ['ok' => false, 'code' => 422, 'message' => $result['message'] ?? 'Gagal push ke Shopee', 'errors' => $result];
        }

        $externalId = $result['external_product_id'] ?? null;

        $this->productRepository->markProductInReview($product, $shop->id, $externalId);

        return ['ok' => true, 'external_product_id' => $externalId];
    }

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

    public function pullProducts(string $shopId, ?\Closure $onProgress = null): int
    {
        $shop = $this->requireShop($shopId);
        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper = app(ShopeeToInternalProductMapper::class);

        $count = 0;
        $failed = 0;
        $total = 0;
        $offset = 0;
        $pageSize = 50;
        $pages = 0;
        $maxPages = (int) config('channel.download_max_pages', 10000);

        do {
            $list = \Modules\Channel\Support\ChannelRetry::run('shopee', fn () => $this->fetchItemList($shop, $offset, $pageSize));

            if ($total === 0) {
                $total = (int) ($list['total_count'] ?? 0);
            }

            $itemIds = $this->extractItemIds($list);

            foreach (array_chunk($itemIds, 50) as $chunk) {
                $baseInfo = \Modules\Channel\Support\ChannelRetry::run('shopee', fn () => $this->fetchBaseInfo($shop, $chunk));

                foreach ($baseInfo as $item) {
                    try {
                        $item = $this->hydrateModels($shop, $item);
                        if ($this->persistItem($shop, $shopId, $item, $mapper, $productService)) {
                            $count++;
                            if ($onProgress) {
                                $onProgress($count, max($total, $count + $failed), $failed);
                            }
                        }
                    } catch (\Throwable $e) {
                        $failed++;
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
            $pages++;

            if ($pages >= $maxPages) {
                Log::warning("Shopee pullProducts: batas {$maxPages} halaman tercapai untuk shop {$shopId}, paginasi dihentikan.", [
                    'shop_id' => $shopId,
                    'downloaded' => $count,
                    'failed' => $failed,
                ]);
                break;
            }
        } while ($hasNext);

        if ($onProgress) {
            $onProgress($count, max($total, $count + $failed), $failed);
        }

        return $count;
    }

    public function listProductIds(string $shopId): array
    {
        $shop = $this->requireShop($shopId);

        $ids = [];
        $offset = 0;
        $pageSize = 50;
        $pages = 0;
        $maxPages = (int) config('channel.download_max_pages', 10000);

        do {
            $list = \Modules\Channel\Support\ChannelRetry::run('shopee', fn () => $this->fetchItemList($shop, $offset, $pageSize));

            foreach ($this->extractItemIds($list) as $id) {
                $ids[] = (string) $id;
            }

            $hasNext = (bool) ($list['has_next_page'] ?? false);
            $offset = (int) ($list['next_offset'] ?? ($offset + $pageSize));
            $pages++;
        } while ($hasNext && $pages < $maxPages);

        return array_values(array_unique($ids));
    }

    public function downloadProductIds(string $shopId, array $externalIds): array
    {
        $shop = $this->requireShop($shopId);
        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper = app(ShopeeToInternalProductMapper::class);

        $downloaded = 0;
        $failed = 0;

        foreach (array_chunk($externalIds, 50) as $chunk) {
            $chunkInts = array_map('intval', $chunk);
            $baseInfo = \Modules\Channel\Support\ChannelRetry::run('shopee', fn () => $this->fetchBaseInfo($shop, $chunkInts));

            foreach ($baseInfo as $item) {
                try {
                    $item = $this->hydrateModels($shop, $item);
                    if ($this->persistItem($shop, $shopId, $item, $mapper, $productService)) {
                        $downloaded++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('Shopee chunk gagal item ' . ($item['item_id'] ?? '?') . ': ' . $e->getMessage());

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

        return ['downloaded' => $downloaded, 'failed' => $failed];
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

        if (isset($item['item_status']) && strtoupper((string) $item['item_status']) !== 'NORMAL') {
            return false;
        }

        $item = $this->hydrateModels($shop, $item);

        $persisted = DB::transaction(fn (): bool => $this->persistItem($shop, $shopId, $item, $mapper, $productService));

        if (! $persisted) {
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

    public function searchProducts(string $shopId, string $query, ?int $timeoutSeconds = null, int $limit = 20): array
    {
        $shop = $this->requireShop($shopId);
        $needle = trim(mb_strtolower($query));

        $results = [];
        $offset = 0;
        $pageSize = 50;
        $pages = 0;
        $limit = max(1, $limit);
        $timeoutSeconds ??= max(1, (int) config('channel.search_remote_timeout_seconds', 8));

        do {
            $list = $this->fetchItemList($shop, $offset, $pageSize, $timeoutSeconds);
            $itemIds = $this->extractItemIds($list);

            $baseItems = $this->fetchBaseInfo($shop, $itemIds, $timeoutSeconds, includeOptionalFields: false);
            $modelLists = $this->shouldHydrateVariantModels($needle)
                ? $this->fetchSearchModelLists($shop, $baseItems, $needle, $timeoutSeconds)
                : [];

            $results = array_merge($results, $this->mapSearchItems($shop, $shopId, $baseItems, $needle, $modelLists));

            $hasNext = (bool) ($list['has_next_page'] ?? false);
            $offset = (int) ($list['next_offset'] ?? ($offset + $pageSize));
            $pages++;
        } while ($hasNext && $pages < 5 && count($results) < $limit);

        return array_slice($results, 0, $limit);
    }

    public function searchProductsPaged(string $shopId, string $query, int $offset, int $limit, ?int $timeoutSeconds = null): array
    {
        $shop = $this->requireShop($shopId);
        $needle = trim(mb_strtolower($query));
        $pageSize = $needle === '' ? min(max($limit, 1), 100) : 50;
        $timeoutSeconds ??= max(1, (int) config('channel.search_remote_timeout_seconds', 8));

        $list = $this->fetchItemList($shop, $offset, $pageSize, $timeoutSeconds);
        $itemIds = $this->extractItemIds($list);
        $baseItems = $this->fetchBaseInfo($shop, $itemIds, $timeoutSeconds, includeOptionalFields: false);
        $modelLists = $this->shouldHydrateVariantModels($needle)
            ? $this->fetchSearchModelLists($shop, $baseItems, $needle, $timeoutSeconds)
            : [];

        $items = $this->mapSearchItems($shop, $shopId, $baseItems, $needle, $modelLists);

        $hasMore = (bool) ($list['has_next_page'] ?? false);
        $nextOffset = $hasMore ? (int) ($list['next_offset'] ?? ($offset + $pageSize)) : null;

        return ['items' => $items, 'next_offset' => $nextOffset, 'has_more' => $hasMore];
    }

    protected function fetchItemList(object $shop, int $offset, int $pageSize, ?int $timeoutSeconds = null): array
    {
        return $this->fetchItemListByStatus($shop, $offset, $pageSize, 'NORMAL', $timeoutSeconds);
    }

    protected function shouldHydrateVariantModels(string $needle): bool
    {
        if ($needle === '' || preg_match('/\s/', $needle) === 1) {
            return false;
        }

        return strpbrk($needle, '-_/') !== false || preg_match('/\d/', $needle) === 1;
    }

    protected function fetchBaseInfo(
        object $shop,
        array $itemIds,
        ?int $timeoutSeconds = null,
        bool $includeOptionalFields = true,
    ): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_item_base_info', [
            'item_id_list' => implode(',', $itemIds),
            'need_complete_description' => $includeOptionalFields,
            'need_complement' => $includeOptionalFields,
        ], $token, $shop->shop_id, $timeoutSeconds));

        return $res['response']['item_list'] ?? [];
    }

    protected function fetchSearchModelLists(object $shop, array $items, string $needle, ?int $timeoutSeconds = null): array
    {
        $requests = [];
        foreach ($items as $item) {
            $baseSearchText = mb_strtolower((string) ($item['item_name'] ?? '') . ' ' . (string) ($item['item_sku'] ?? ''));
            if (empty($item['has_model'])
                || empty($item['item_id'])
                || str_contains($baseSearchText, $needle)) {
                continue;
            }

            $requests[(string) $item['item_id']] = ['item_id' => (int) $item['item_id']];
        }

        if ($requests === []) {
            return [];
        }

        $modelLists = [];
        foreach (array_chunk($requests, 8, true) as $batch) {
            try {
                $responses = $this->callWithRefresh($shop, fn (string $token): array => $this->client->requestPool(
                    '/api/v2/product/get_model_list',
                    $batch,
                    $token,
                    $shop->shop_id,
                    $timeoutSeconds,
                ));
            } catch (\Throwable $e) {

                Log::warning('Shopee variant search hydration skipped', [
                    'shop_id' => $shop->shop_id,
                    'batch_size' => count($batch),
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($responses as $itemId => $response) {
                $modelLists[(string) $itemId] = [
                    'models' => $response['response']['model'] ?? [],
                    'tier_variation' => $response['response']['tier_variation'] ?? [],
                ];
            }
        }

        return $modelLists;
    }

    protected function mapSearchItems(object $shop, string $shopId, array $items, string $needle, array $modelLists): array
    {
        $results = [];
        foreach ($items as $item) {
            $name = (string) ($item['item_name'] ?? '');
            $itemId = (string) ($item['item_id'] ?? '');
            $models = $modelLists[$itemId]['models'] ?? [];
            $sellerSkus = collect([$item['item_sku'] ?? null])
                ->merge(collect($models)->pluck('model_sku'))
                ->filter(fn ($sku) => is_string($sku) && trim($sku) !== '')
                ->map(fn ($sku) => trim((string) $sku))
                ->unique(fn ($sku) => mb_strtolower($sku))
                ->values()
                ->all();
            $matchingSku = $this->matchingSearchSku($sellerSkus, $needle);

            if ($needle !== '' && $matchingSku === null && ! str_contains(mb_strtolower($name), $needle)) {
                continue;
            }

            $results[] = [
                'external_product_id' => $itemId,
                'name' => $name,
                'seller_sku' => $matchingSku ?: ($sellerSkus[0] ?? null),
                'seller_skus' => $sellerSkus,
                'image' => $item['image']['image_url_list'][0] ?? null,
                'shop_id' => $shopId,
                'shop_name' => $shop->shop_name ?? null,
                'channel_code' => 'shopee',
            ];
        }

        return $results;
    }

    protected function matchingSearchSku(array $sellerSkus, string $needle): ?string
    {
        if ($needle === '') {
            return null;
        }

        foreach ($sellerSkus as $sellerSku) {
            if (mb_strtolower($sellerSku) === $needle) {
                return $sellerSku;
            }
        }

        foreach ($sellerSkus as $sellerSku) {
            if (str_contains(mb_strtolower($sellerSku), $needle)) {
                return $sellerSku;
            }
        }

        return null;
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
        $matchedExisting = false;
        $variantIds = [];
        $internalData = $mapper->map($item, $shopId);
        $insertedId = $productService->upsertFromChannel($internalData, $matchedExisting, $variantIds);
        if (! $insertedId) {
            return false;
        }

        $pcmId = $this->productRepository->upsertChannelMapping(
            (string) $insertedId,
            $shopId,
            (string) ($item['item_id'] ?? ''),
            'synced',
            null,
            false
        );

        $models = $item['model_list'] ?? [];

        if (empty($models)) {
            if (! $matchedExisting) {
                $variantId = $variantIds[0] ?? null;
            } else {
                $sku = $item['item_sku'] ?? null;
                $variant = $sku ? $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku) : null;
                $variantId = $variant->id ?? null;
            }

            if ($variantId) {
                $this->productRepository->upsertVariantChannelMapping(
                    $pcmId,
                    $variantId,
                    null,
                    $item['item_sku'] ?? null,
                    $item['price_info'][0]['current_price'] ?? null
                );
            }

            return true;
        }

        $this->linkModels($shop, $shopId, $item, $models, $internalData, (string) $insertedId, $pcmId, $variantIds);

        return true;
    }

    protected function linkModels(
        object $shop,
        string $shopId,
        array $item,
        array $models,
        array $internalData,
        string $defaultProductId,
        string $defaultPcmId,
        array $variantIds = []
    ): void {
        $variantsByIndex = array_values($internalData['variants'] ?? []);

        $normalized = [];

        foreach ($models as $idx => $model) {
            $normalized[] = [
                'sku' => $model['model_sku'] ?? null,
                'external_sku_id' => $model['model_id'] ?? null,
                'price' => $model['price_info'][0]['current_price'] ?? $model['original_price'] ?? null,
                'group' => $model['tier_index'][0] ?? null,
                'variant' => $variantsByIndex[$idx] ?? [],
                'fallback_variant_id' => $variantIds[$idx] ?? null,
            ];
        }

        app(ChannelModelLinker::class)->link(
            $shop,
            $shopId,
            (string) ($item['item_id'] ?? ''),
            $normalized,
            $defaultProductId,
            $defaultPcmId
        );
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

    public function fetchProductStatuses(string $shopId): array
    {
        $statuses = [];

        $this->eachProductStatusPage($shopId, function (array $page) use (&$statuses) {
            $statuses = array_replace($statuses, $page);
        });

        return $statuses;
    }

    public function eachProductStatusPage(string $shopId, callable $onPage): void
    {
        $shop = $this->requireShop($shopId);

        foreach (['NORMAL', 'BANNED', 'DELETED', 'UNLIST'] as $itemStatus) {
            $offset = 0;
            $pageSize = 100;

            do {
                $list = $this->fetchItemListByStatus($shop, $offset, $pageSize, $itemStatus);
                $page = [];

                foreach ($list['item'] ?? [] as $item) {
                    $extId = (string) ($item['item_id'] ?? '');
                    if ($extId === '') {
                        continue;
                    }
                    $page[$extId] = [
                        'status' => strtolower($itemStatus),
                        'reason' => null,
                    ];
                }

                if ($page !== []) {
                    $onPage($page);
                }

                $hasNext = (bool) ($list['has_next_page'] ?? false);
                $offset = (int) ($list['next_offset'] ?? ($offset + $pageSize));
            } while ($hasNext);
        }
    }

    protected function fetchItemListByStatus(object $shop, int $offset, int $pageSize, string $itemStatus, ?int $timeoutSeconds = null): array
    {
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/product/get_item_list', [
            'offset' => $offset,
            'page_size' => $pageSize,
            'item_status' => $itemStatus,
        ], $token, $shop->shop_id, $timeoutSeconds));

        return $res['response'] ?? [];
    }

    public function boostItem(string $shopId, array $itemIds): array
    {
        $shop = $this->requireShop($shopId);
        $results = [];

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

    public function reconcileChannelData(string $shopId): int
    {
        $shop = $this->requireShop($shopId);
        $channelShopId = $shop->id;

        $statuses = $this->fetchProductStatuses($shopId);

        $mappings = ProductChannelMapping::where('channel_shop_id', $channelShopId)
            ->whereNotNull('external_product_id')
            ->get();

        $updated = 0;
        $activeIds = [];

        foreach ($mappings as $mapping) {
            $extId = (string) $mapping->external_product_id;
            $remoteStatus = $statuses[$extId]['status'] ?? null;

            if ($remoteStatus === 'deleted') {

                $mapping->markAsFailed('Produk sudah dihapus di Shopee (terdeteksi reconcile).');
                $updated++;
                continue;
            }

            $activeIds[] = $extId;
        }

        foreach (array_chunk($activeIds, 50) as $chunk) {
            foreach ($this->fetchBaseInfo($shop, $chunk) as $item) {
                $extId = (string) ($item['item_id'] ?? '');
                if ($extId === '') {
                    continue;
                }

                $mapping = $mappings->firstWhere('external_product_id', $extId);
                if (! $mapping) {
                    continue;
                }

                $item = $this->hydrateModels($shop, $item);
                $models = $item['model_list'] ?? [];

                if (empty($models)) {
                    $price = $item['price_info'][0]['current_price'] ?? null;
                    if ($price !== null) {
                        ProductVariantChannelMapping::where('product_channel_mapping_id', $mapping->id)
                            ->update(['synced_price' => $price]);
                    }
                } else {
                    foreach ($models as $model) {
                        $modelId = isset($model['model_id']) ? (string) $model['model_id'] : null;
                        if ($modelId === null) {
                            continue;
                        }

                        $vm = ProductVariantChannelMapping::where('product_channel_mapping_id', $mapping->id)
                            ->where('external_sku_id', $modelId)
                            ->first();
                        if (! $vm) {
                            continue;
                        }

                        $price = $model['price_info'][0]['current_price'] ?? $model['original_price'] ?? null;
                        $vmUpdate = [];
                        if (! empty($model['model_sku'])) {
                            $vmUpdate['channel_seller_sku'] = $model['model_sku'];
                        }
                        if ($price !== null) {
                            $vmUpdate['synced_price'] = $price;
                        }
                        if ($vmUpdate) {
                            $vm->update($vmUpdate);
                        }
                    }
                }

                $updated++;
            }
        }

        return $updated;
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
