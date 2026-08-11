<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Product\Models\ProductSyncLog;

class LazadaProductService
{

    private const PULL_PAGE_LIMIT = 20;     

    private const SEARCH_PAGE_LIMIT = 10;   

    public function __construct(
        protected LazadaClient $client,
        protected LazadaToInternalProductMapper $inboundMapper,
        protected ChannelShopRepository $shopRepository,
        protected ChannelProductRepository $productRepository,
        protected LazadaAuthService $authService,
    ) {}

    public function pushProductListing(string $shopId, string $productId): array
    {
        $shop = $this->shopRepository->findConnectedByShopId($shopId);
        if (! $shop) {
            return ['ok' => false, 'code' => 404, 'message' => 'Toko Lazada tidak ditemukan / terputus'];
        }

        $product = $this->productRepository->findProductModelWithVariantsAndMedia($productId);
        if (! $product) {
            return ['ok' => false, 'code' => 404, 'message' => 'Produk tidak ditemukan'];
        }

        $issues = app(\Modules\Channel\Services\ChannelListingValidator::class)->validate($product, 'lazada');
        if (! empty($issues)) {
            return ['ok' => false, 'code' => 422, 'message' => 'Produk belum siap di-listing ke Lazada', 'errors' => ['issues' => $issues]];
        }

        $result = app(\Modules\Channel\Adapters\LazadaAdapter::class)->pushProduct($product, $shop);

        if (! ($result['success'] ?? false)) {
            return ['ok' => false, 'code' => 422, 'message' => $result['message'] ?? 'Gagal push ke Lazada', 'errors' => $result];
        }

        $externalId = $result['external_product_id'] ?? null;

        $this->productRepository->markProductInReview($product, $shop->id, $externalId);

        return ['ok' => true, 'external_product_id' => $externalId];
    }

    public function validateListing(string $productId): array
    {
        $product = $this->productRepository->findProductModel($productId);
        if (! $product) {
            return ['ok' => false, 'code' => 404, 'message' => 'Produk tidak ditemukan'];
        }

        $issues = app(\Modules\Channel\Services\ChannelListingValidator::class)->validate($product, 'lazada');

        return [
            'ok' => true,
            'ready' => empty($issues),
            'issues' => $issues,
        ];
    }

    public function fetchProductStatuses(string $shopId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return [];
        }

        $statuses = [];
        $offset = 0;
        $limit = self::PULL_PAGE_LIMIT;

        do {
            $params = ['filter' => 'all', 'offset' => $offset, 'limit' => $limit];

            try {
                $res = $this->client->request('GET', '/products/get', $params, $shop->access_token);
            } catch (TokenExpiredException $e) {
                $this->authService->refreshStoreToken((string) $shop->id);
                $shop = $this->shopRepository->findByShopId($shopId);
                $res = $this->client->request('GET', '/products/get', $params, $shop->access_token);
            }

            $products = $res['data']['products'] ?? [];

            foreach ($products as $item) {
                $extId = (string) ($item['item_id'] ?? '');
                if ($extId === '') {
                    continue;
                }
                $reason = $item['reasons'] ?? $item['reason'] ?? null;
                $statuses[$extId] = [
                    'status' => strtolower((string) ($item['qc_status'] ?? $item['status'] ?? '')),
                    'reason' => is_array($reason) ? json_encode($reason) : $reason,
                ];
            }

            $offset += $limit;
        } while (count($products) === $limit);

        return $statuses;
    }

    public function syncCategoryTree(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Lazada tidak terhubung: {$shopId}");
        }

        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel lazada belum ter-seed.');
        }

        $res = $this->client->request('GET', '/category/tree/get', [], $shop->access_token);
        $nodes = $res['data'] ?? [];

        $count = 0;
        $seen = [];
        $walk = function (array $list, string $parent) use (&$walk, &$count, &$seen, $channelId) {
            foreach ($list as $node) {
                $extId = (string) ($node['category_id'] ?? '');
                if ($extId === '') {
                    continue;
                }
                $seen[] = $extId;

                $values = [
                    'parent_external_id' => $parent,
                    'name' => (string) ($node['name'] ?? ''),
                    'is_leaf' => (bool) ($node['leaf'] ?? false),
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
                        'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                        'channel_id' => $channelId,
                        'external_id' => $extId,
                        'created_at' => now(),
                    ]);
                }
                $count++;

                if (! empty($node['children']) && is_array($node['children'])) {
                    $walk($node['children'], $extId);
                }
            }
        };

        $walk(is_array($nodes) ? $nodes : [], '0');

        if (! empty($seen)) {
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

            $this->refreshMappingStaleness($channelId);
        }

        return $count;
    }

    protected function refreshMappingStaleness($channelId): void
    {
        $deprecated = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->whereNotNull('deprecated_at')
            ->pluck('id');

        $active = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->whereNull('deprecated_at')
            ->pluck('id');

        if ($deprecated->isNotEmpty()) {
            DB::table('category_channel_mappings')
                ->whereIn('channel_category_id', $deprecated)
                ->update(['is_stale' => true, 'updated_at' => now()]);
        }

        if ($active->isNotEmpty()) {
            DB::table('category_channel_mappings')
                ->whereIn('channel_category_id', $active)
                ->update(['is_stale' => false, 'last_verified_at' => now(), 'updated_at' => now()]);
        }
    }

    public function syncCategoryAttributes(string $shopId, string $categoryExtId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Lazada tidak terhubung: {$shopId}");
        }

        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel lazada belum ter-seed.');
        }

        $channelCategory = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->where('external_id', $categoryExtId)
            ->first();
        if (! $channelCategory) {
            throw new \Exception("Kategori Lazada {$categoryExtId} belum disinkronkan (jalankan sinkron kategori dulu).");
        }

        $params = ['primary_category_id' => $categoryExtId];

        try {
            $res = $this->client->request('GET', '/category/attributes/get', $params, $shop->access_token);
        } catch (TokenExpiredException $e) {
            $this->authService->refreshStoreToken((string) $shop->id);
            $shop = $this->shopRepository->findByShopId($shopId);
            $res = $this->client->request('GET', '/category/attributes/get', $params, $shop->access_token);
        }

        $attributes = $res['data'] ?? [];
        if (! is_array($attributes)) {
            return 0;
        }

        $count = 0;

        foreach ($attributes as $attr) {

            $extId = (string) ($attr['name'] ?? '');
            if ($extId === '') {
                continue;
            }

            $inputType = strtolower((string) ($attr['input_type'] ?? ''));

            $values = [
                'name' => (string) ($attr['label'] ?? $attr['name'] ?? ''),
                'is_required' => (bool) ($attr['is_mandatory'] ?? false),
                'is_multiple' => in_array($inputType, ['multiselect', 'multienuminput', 'checkbox'], true),
                'is_sale_prop' => (bool) ($attr['is_sale_prop'] ?? false),
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
                $channelAttributeId = \Ramsey\Uuid\Uuid::uuid7()->toString();
                DB::table('channel_attributes')->insert($values + [
                    'id' => $channelAttributeId,
                    'channel_category_id' => $channelCategory->id,
                    'external_id' => $extId,
                    'created_at' => now(),
                ]);
            }
            $count++;

            foreach ($attr['options'] ?? [] as $opt) {
                $optExtId = (string) ($opt['name'] ?? $opt['id'] ?? '');
                if ($optExtId === '') {
                    continue;
                }

                $optValues = [
                    'name' => (string) ($opt['name'] ?? $optExtId),
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
                        'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
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
        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel lazada belum ter-seed.');
        }

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

    /**
     * Detail listing apa adanya dari Lazada, dipakai rekonsiliasi stok untuk
     * membaca kuantitas yang sedang tayang.
     */
    public function fetchLiveProduct(string $shopId, string $itemId): ?array
    {
        $shop = $this->shopRepository->findByShopId($shopId);

        if (! $shop || ! $shop->access_token) {
            return null;
        }

        $params = ['item_id' => $itemId];

        try {
            $res = $this->client->request('GET', '/product/item/get', $params, $shop->access_token);
        } catch (TokenExpiredException $e) {
            $this->authService->refreshStoreToken((string) $shop->id);
            $shop = $this->shopRepository->findByShopId($shopId);
            $res = $this->client->request('GET', '/product/item/get', $params, $shop->access_token);
        }

        $item = $res['data'] ?? null;

        return is_array($item) && $item !== [] ? $item : null;
    }

    public function pullProductById(string $shopId, string $itemId): bool
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return false;
        }

        $params = ['item_id' => $itemId];

        try {
            $res = $this->client->request('GET', '/product/item/get', $params, $shop->access_token);
        } catch (TokenExpiredException $e) {
            $this->authService->refreshStoreToken((string) $shop->id);
            $shop = $this->shopRepository->findByShopId($shopId);
            $res = $this->client->request('GET', '/product/item/get', $params, $shop->access_token);
        }

        $item = $res['data'] ?? null;
        if (! is_array($item) || empty($item)) {
            return false;
        }

        $status = strtolower((string) ($item['status'] ?? ''));
        if ($status !== '' && $status !== 'active' && $status !== 'live') {
            Log::info("Lazada download dilewati: item {$itemId} tidak aktif (status {$status})");

            return false;
        }

        $productService = app(\Modules\Product\Services\ProductService::class);

        try {
            $matchedExisting = false;
            $internalData = $this->inboundMapper->map($item, $shopId);
            $insertedId = $productService->upsertFromChannel($internalData, $matchedExisting);
        } catch (\Throwable $e) {
            Log::error('Lazada: gagal re-sync produk ' . $itemId . ': ' . $e->getMessage());

            return false;
        }

        if (! $insertedId) {
            return false;
        }

        $this->productRepository->upsertChannelMapping(
            (string) $insertedId,
            $shopId,
            (string) ($item['item_id'] ?? $itemId),
            'synced',
            null,
            false
        );

        return true;
    }

    public function searchProducts(string $shopId, string $query): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return [];
        }

        $needle  = trim(mb_strtolower($query));
        $results = [];
        $offset  = 0;
        $limit   = self::SEARCH_PAGE_LIMIT;
        $pages   = 0;

        do {

            $params = ['filter' => 'live', 'offset' => $offset, 'limit' => $limit];
            if ($needle !== '') {
                $params['search'] = $query;
            }

            try {
                $res = $this->client->request('GET', '/products/get', $params, $shop->access_token);
            } catch (TokenExpiredException $e) {
                $this->authService->refreshStoreToken((string) $shop->id);
                $shop = $this->shopRepository->findByShopId($shopId);
                $res = $this->client->request('GET', '/products/get', $params, $shop->access_token);
            }

            $products = $res['data']['products'] ?? [];

            foreach ($products as $item) {
                $status = strtolower((string) ($item['status'] ?? ''));
                if ($status !== '' && $status !== 'active' && $status !== 'live') {
                    continue;
                }

                $name      = (string) ($item['attributes']['name'] ?? '');
                $sellerSku = null;
                foreach ($item['skus'] ?? [] as $sku) {
                    if (! empty($sku['SellerSku'])) {
                        $sellerSku = $sku['SellerSku'];
                        break;
                    }
                }

                if ($needle !== '' && ! str_contains(mb_strtolower($name . ' ' . (string) $sellerSku), $needle)) {
                    continue;
                }

                $results[] = [
                    'external_product_id' => (string) ($item['item_id'] ?? ''),
                    'name'                => $name,
                    'seller_sku'          => $sellerSku,
                    'image'               => $item['images'][0] ?? null,
                    'shop_id'             => $shopId,
                    'shop_name'           => $shop->shop_name ?? null,
                    'channel_code'        => 'lazada',
                ];
            }

            $offset += $limit;
            $pages++;
        } while (count($products) === $limit && $pages < 5 && count($results) < 200);

        return $results;
    }

    public function pullProducts(string $shopId, ?\Closure $onProgress = null): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Lazada tidak ditemukan atau belum terhubung: {$shopId}");
        }

        $productService = app(\Modules\Product\Services\ProductService::class);

        $count = 0;
        $failed = 0;
        $total = 0;
        $offset = 0;
        $limit = self::PULL_PAGE_LIMIT;
        $pages = 0;
        $maxPages = (int) config('channel.download_max_pages', 10000);

        do {

            $params = ['filter' => 'live', 'offset' => $offset, 'limit' => $limit];

            $res = \Modules\Channel\Support\ChannelRetry::run('lazada', function () use (&$shop, $shopId, $params) {
                try {
                    return $this->client->request('GET', '/products/get', $params, $shop->access_token);
                } catch (TokenExpiredException $e) {
                    $this->authService->refreshStoreToken((string) $shop->id);
                    $shop = $this->shopRepository->findByShopId($shopId);

                    return $this->client->request('GET', '/products/get', $params, $shop->access_token);
                }
            });

            if ($total === 0) {
                $total = (int) ($res['data']['total_products'] ?? 0);
            }

            $products = $res['data']['products'] ?? [];

            foreach ($products as $item) {
                $status = strtolower((string) ($item['status'] ?? ''));
                if ($status !== '' && $status !== 'active' && $status !== 'live') {
                    continue;
                }

                try {
                    $matchedExisting = false;
                    $variantIds = [];
                    $internalData = $this->inboundMapper->map($item, $shopId);
                    $insertedId = $productService->upsertFromChannel($internalData, $matchedExisting, $variantIds);

                    if ($insertedId) {
                        $pcmId = $this->productRepository->upsertChannelMapping(
                            (string) $insertedId,
                            $shopId,
                            (string) ($item['item_id'] ?? ''),
                            'synced',
                            (! empty($item['attributes']) && is_array($item['attributes'])) ? $item['attributes'] : null,
                            false
                        );

                        foreach ($item['skus'] ?? [] as $idx => $skuData) {
                            if (! $matchedExisting) {
                                $variantId = $variantIds[$idx] ?? null;
                            } else {
                                $variant = $this->productRepository->getVariantByProductIdAndSku(
                                    (string) $insertedId,
                                    $skuData['SellerSku'] ?? null
                                );
                                $variantId = $variant->id ?? null;
                            }

                            if ($variantId) {
                                $this->productRepository->upsertVariantChannelMapping(
                                    $pcmId,
                                    $variantId,
                                    isset($skuData['SkuId']) ? (string) $skuData['SkuId'] : null,
                                    $skuData['SellerSku'] ?? null,
                                    $skuData['price'] ?? null
                                );
                            }
                        }

                        $count++;
                        if ($onProgress) {
                            $onProgress($count, max($total, $count + $failed), $failed);
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('Lazada: gagal pull produk ' . ($item['item_id'] ?? '?') . ': ' . $e->getMessage());

                    ProductSyncLog::record([
                        'channel_shop_id' => $shop->id,
                        'action' => ProductSyncLog::ACTION_DOWNLOAD,
                        'status' => ProductSyncLog::STATUS_FAILED,
                        'payload' => [
                            'external_product_id' => $item['item_id'] ?? null,
                            'title' => $item['attributes']['name'] ?? null,
                        ],
                        'error_message' => $e->getMessage(),
                    ]);
                }
            }

            $offset += $limit;
            $pages++;

            if ($pages >= $maxPages) {
                Log::warning("Lazada pullProducts: batas {$maxPages} halaman tercapai untuk shop {$shopId}, paginasi dihentikan.", [
                    'shop_id' => $shopId,
                    'downloaded' => $count,
                    'failed' => $failed,
                ]);
                break;
            }
        } while (count($products) === $limit);

        if ($onProgress) {
            $onProgress($count, max($total, $count + $failed), $failed);
        }

        return $count;
    }

    public function reconcileChannelData(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Lazada tidak ditemukan atau belum terhubung: {$shopId}");
        }

        $channelShopId = $shop->id;
        $updated = 0;
        $offset = 0;
        $limit = self::PULL_PAGE_LIMIT;

        do {
            $params = ['filter' => 'all', 'offset' => $offset, 'limit' => $limit];

            try {
                $res = $this->client->request('GET', '/products/get', $params, $shop->access_token);
            } catch (TokenExpiredException $e) {
                $this->authService->refreshStoreToken((string) $shop->id);
                $shop = $this->shopRepository->findByShopId($shopId);
                $res = $this->client->request('GET', '/products/get', $params, $shop->access_token);
            }

            $products = $res['data']['products'] ?? [];

            foreach ($products as $item) {
                $mapping = \Modules\Product\Models\ProductChannelMapping::where('external_product_id', (string) ($item['item_id'] ?? ''))
                    ->where('channel_shop_id', $channelShopId)
                    ->first();

                if (! $mapping) {
                    continue;
                }

                $attrs = (! empty($item['attributes']) && is_array($item['attributes'])) ? $item['attributes'] : null;
                $canonical = \Modules\Channel\Repositories\ChannelProductRepository::canonicalAttributes($attrs);
                $mapping->update([
                    'channel_attributes' => $canonical !== null ? json_decode($canonical, true) : null,
                ]);

                foreach ($item['skus'] ?? [] as $skuData) {
                    if (empty($skuData['SkuId'])) {
                        continue;
                    }
                    $vm = \Modules\Product\Models\ProductVariantChannelMapping::where('product_channel_mapping_id', $mapping->id)
                        ->where('external_sku_id', (string) $skuData['SkuId'])
                        ->first();
                    if (! $vm) {
                        continue;
                    }
                    $vmUpdate = [];
                    if (! empty($skuData['SellerSku'])) {
                        $vmUpdate['channel_seller_sku'] = $skuData['SellerSku'];
                    }
                    if (isset($skuData['price'])) {
                        $vmUpdate['synced_price'] = $skuData['price'];
                    }
                    if ($vmUpdate) {
                        $vm->update($vmUpdate);
                    }
                }

                $updated++;
            }

            $offset += $limit;
        } while (count($products) === $limit);

        return $updated;
    }
}
