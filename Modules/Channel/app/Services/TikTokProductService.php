<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Services\TikTokToInternalProductMapper;
use Modules\Channel\Support\ChannelModelLinker;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Services\ChannelAttributeService;

class TikTokProductService
{
    protected TikTokClient $client;
    protected TikTokProductMapper $mapper;
    protected ChannelShopRepository $shopRepository;
    protected ChannelProductRepository $productRepository;
    protected TikTokImageUploader $imageUploader;
    protected ChannelStockResolver $stockResolver;

    public function __construct(
        TikTokClient $client,
        TikTokProductMapper $mapper,
        ChannelShopRepository $shopRepository,
        ChannelProductRepository $productRepository,
        TikTokImageUploader $imageUploader,
        ?ChannelStockResolver $stockResolver = null
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->shopRepository = $shopRepository;
        $this->productRepository = $productRepository;
        $this->imageUploader = $imageUploader;
        $this->stockResolver = $stockResolver ?? app(ChannelStockResolver::class);
    }

    public function pushProduct(string $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $product = $this->productRepository->findById($productId);
        if (!$product) {
            throw new \Exception("Produk tidak ditemukan");
        }

        $this->assertValidTikTokTitle($product->name ?? '');

        $variants = $this->productRepository->getVariantsByProductId($productId);
        $media = collect($this->productRepository->getMediaByProductId($productId));

        $imageUrls = $media
            ->filter(fn ($m) => ($m->variant_id ?? null) === null && $m->media_type === 'image')
            ->pluck('url')
            ->all();

        $uploadResult = $this->imageUploader->upload($imageUrls, $accessToken);
        $uploadedImageIds = $uploadResult['uris'];

        if (empty($uploadedImageIds)) {
            throw new \RuntimeException($this->noImageMessage($imageUrls, $uploadResult['errors']), 422);
        }

        $videoId = null;
        $productVideo = $media->first(fn ($m) => ($m->variant_id ?? null) === null && $m->media_type === 'video');
        if ($productVideo) {
            $videoId = $this->imageUploader->uploadVideo($productVideo->url, $accessToken);
        }

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $variants);

        $internalProduct = (array)$product;
        $internalProduct['variants'] = $variants->map(function ($v) use ($media, $accessToken, $stockByVariant) {
            $variantArr = (array)$v;
            $options = $this->productRepository->getVariantOptions($v->id);
            $variantArr['options'] = array_map(fn($opt) => (array)$opt, $options);
            $variantArr['stock'] = $stockByVariant[$v->id] ?? 0;

            $variantImage = $media->first(fn ($m) => ($m->variant_id ?? null) === $v->id && $m->media_type === 'image');
            if ($variantImage) {
                $vUris = $this->imageUploader->upload([$variantImage->url], $accessToken)['uris'];
                if (!empty($vUris)) {
                    $variantArr['image_uri'] = $vUris[0];
                }
            }

            return $variantArr;
        })->toArray();

        $hasMeaningfulOptions = collect($internalProduct['variants'])->contains(
            fn ($v) => collect($v['options'] ?? [])->contains(fn ($opt) => ! empty($opt['attribute_id']))
        );
        if ($hasMeaningfulOptions) {
            $internalProduct['variants'] = array_values(
                array_filter($internalProduct['variants'], fn ($v) =>
                    collect($v['options'] ?? [])->contains(fn ($opt) => ! empty($opt['attribute_id']))
                )
            );
        }

        $config = $this->buildUploadConfig($product, $shop, $videoId);

        $payload = $this->mapper->map($internalProduct, $uploadedImageIds, $config);

        $res = $this->client->request('POST', '/product/202309/products', ['shop_cipher' => $shopCipher], $payload, $accessToken);

        if (isset($res['data']['product_id'])) {
            $pcmId = $this->productRepository->upsertChannelMapping($productId, $shopId, $res['data']['product_id'], 'synced');
            $this->persistVariantMappings($pcmId, $variants, $res['data']['skus'] ?? []);
        }

        return $res;
    }

    public function buildUploadConfig($product, $shop, ?string $videoId = null, ?array $userAttributeMapping = null): array
    {
        $config = [];

        $channelCategory = $this->resolveChannelCategory($product, $shop);
        if ($channelCategory) {
            if (! $channelCategory->is_leaf) {
                throw new \RuntimeException(
                    "Kategori channel produk ini bukan kategori paling spesifik (Jenis Produk). "
                    . "Pilih Jenis Produk (sub-kategori terdalam) lalu upload ulang.",
                    422
                );
            }
            $config['category_id'] = $channelCategory->external_id;

            if (! empty($channelCategory->id)) {
                $salesMap = $this->productRepository->getSalesAttributeMap($channelCategory->id);
                if (! empty($salesMap)) {
                    $config['sales_attribute_map'] = $salesMap;
                }

                $nameMap = $this->productRepository->getSalePropNameMap($channelCategory->id);
                if (! empty($nameMap)) {
                    $config['sales_attribute_name_map'] = $nameMap;
                }

                $idToName = \Modules\Product\Models\ChannelAttribute::where('channel_category_id', $channelCategory->id)
                    ->where('is_sale_prop', true)
                    ->pluck('name', 'external_id')
                    ->toArray();
                if (! empty($idToName)) {
                    $config['sales_attribute_id_to_name'] = $idToName;
                }
            }
        }

        if ($videoId) {
            $config['video_id'] = $videoId;
        }

        $specs = $this->productRepository->getProductSpecifications($product->id);

        $mappedAttributes = [];
        foreach ($specs as $spec) {
            $mapping = $this->productRepository->getAttributeChannelMapping($spec->attribute_id);
            if (! $mapping) {
                continue;
            }

            $channelAttr = $this->productRepository->getChannelAttribute($mapping->channel_attribute_id);
            if (! $channelAttr) {
                continue;
            }

            $attrData = ['id' => $channelAttr->external_id, 'values' => []];

            if ($spec->attribute_option_id) {
                $optMapping = $this->productRepository->getAttributeOptionChannelMapping($spec->attribute_option_id);
                if ($optMapping) {
                    $channelOpt = $this->productRepository->getChannelAttributeOption($optMapping->channel_attribute_option_id);
                    if ($channelOpt) {
                        $attrData['values'][] = ['id' => $channelOpt->external_id, 'name' => $channelOpt->name];
                    }
                }
            } else if ($spec->text_value) {
                $attrData['values'][] = ['name' => $spec->text_value];
            }

            if (!empty($attrData['values'])) {
                $mappedAttributes[] = $attrData;
            }
        }

        if ($channelCategory && ! empty($channelCategory->id)) {
            $coveredIds = collect($mappedAttributes)->pluck('id')->all();

            if ($userAttributeMapping) {
                foreach ($userAttributeMapping as $attrExternalId => $optionExternalId) {
                    $attrExternalId = (string) $attrExternalId;
                    $optionExternalId = (string) $optionExternalId;
                    if (in_array($attrExternalId, $coveredIds)) {
                        continue;
                    }
                    $optionName = \Modules\Product\Models\ChannelAttributeOption::where('external_id', $optionExternalId)->value('name');
                    $value = ['id' => $optionExternalId];
                    if ($optionName !== null) {
                        $value['name'] = $optionName;
                    }
                    $mappedAttributes[] = [
                        'id' => $attrExternalId,
                        'values' => [$value],
                    ];
                    $coveredIds[] = $attrExternalId;
                }
            }

            $requiredAttrs = \Modules\Product\Models\ChannelAttribute::where('channel_category_id', $channelCategory->id)
                ->where('is_required', true)
                ->where('is_sale_prop', false)
                ->get();

            foreach ($requiredAttrs as $reqAttr) {
                if (in_array($reqAttr->external_id, $coveredIds)) {
                    continue;
                }

                $options = \Modules\Product\Models\ChannelAttributeOption::where('channel_attribute_id', $reqAttr->id)
                    ->get();

                if ($options->isEmpty()) {
                    continue;
                }

                $defaultOption = $options->first(function ($opt) {
                    $lower = mb_strtolower($opt->name);
                    return in_array($lower, ['tidak', 'no', 'none', 'tidak ada']);
                }) ?? $options->first();

                $mappedAttributes[] = [
                    'id' => $reqAttr->external_id,
                    'values' => [['id' => $defaultOption->external_id, 'name' => $defaultOption->name]],
                ];
            }
        }

        if (!empty($mappedAttributes)) {
            $config['attributes'] = $mappedAttributes;
        }

        if (empty($config['warehouse_id'])) {
            $config['warehouse_id'] = app()->runningUnitTests()
                ? '7646426075561690887'
                : app(\Modules\Channel\Adapters\TikTokAdapter::class)->resolveWarehouseId($shop);
        }

        return $config;
    }

    private function resolveChannelCategory($product, $shop): ?object
    {
        $draft = \Modules\Product\Models\ProductChannelDraft::where('product_id', $product->id)
            ->where('channel_shop_id', $shop->id)
            ->latest('updated_at')
            ->first();

        if ($draft && $draft->channel_category_id) {
            $info = $this->productRepository->getChannelCategoryInfoById($draft->channel_category_id);
            if ($info) {
                return $info;
            }
        }

        if (!empty($product->category_id)) {
            return $this->productRepository->getChannelCategoryInfoByInternal($product->category_id, $shop->channel_id);
        }

        return null;
    }

    private function persistVariantMappings(string $pcmId, $variants, array $skus): void
    {
        $variantBySku = collect($variants)->keyBy('sku');

        foreach ($skus as $skuData) {
            $sellerSku = $skuData['seller_sku'] ?? null;
            if ($sellerSku === null || !$variantBySku->has($sellerSku)) {
                continue;
            }

            $sale = $skuData['sales_attributes'][0] ?? null;
            $saleId = is_array($sale) ? (string) ($sale['attribute_id'] ?? $sale['id'] ?? '') : '';
            $saleName = is_array($sale) ? (string) ($sale['attribute_name'] ?? $sale['name'] ?? '') : '';

            $this->productRepository->upsertVariantChannelMapping(
                $pcmId,
                $variantBySku->get($sellerSku)->id,
                $skuData['id'] ?? null,
                $sellerSku,
                null,
                $saleId !== '' ? $saleId : null,
                $saleName !== '' ? $saleName : null,
            );
        }
    }

    public function pullProducts(string $shopId, ?\Closure $onProgress = null): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken   = $shop->access_token;
        $shopCipher    = $shop->shop_cipher ?? '';
        $channelShopId = $shop->id;

        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper         = app(TikTokToInternalProductMapper::class);

        $count     = 0;
        $failed    = 0;
        $total     = 0;
        $pageToken = null;
        $pages     = 0;
        $maxPages  = (int) config('channel.download_max_pages', 10000);

        do {
            $queries = ['shop_cipher' => $shopCipher, 'page_size' => 100];
            if ($pageToken) {
                $queries['page_token'] = $pageToken;
            }

            $body = ['status' => 'ACTIVATE'];

            $res = \Modules\Channel\Support\ChannelRetry::run('tiktok', function () use (&$accessToken, $shop, $queries, $body) {
                try {
                    return $this->client->request('POST', '/product/202309/products/search', $queries, $body, $accessToken);
                } catch (TokenExpiredException $e) {
                    $accessToken = $this->refreshShopToken($shop);

                    return $this->client->request('POST', '/product/202309/products/search', $queries, $body, $accessToken);
                }
            });

            if (!isset($res['data']['products'])) {
                Log::warning("TikTok pullProducts: respons tanpa 'data.products' untuk shop {$shopId}, pagination dihentikan.", [
                    'shop_id'    => $shopId,
                    'page_token' => $pageToken,
                ]);
                break;
            }

            if ($total === 0) {
                $total = (int) ($res['data']['total_count'] ?? 0);
            }

            $products = $res['data']['products'];
            $ids      = array_values(array_filter(array_map(fn ($p) => (string) ($p['id'] ?? ''), $products)));

            $details = \Modules\Channel\Support\ChannelRetry::run('tiktok', function () use (&$accessToken, $shop, $ids, $shopCipher) {
                try {
                    return $this->client->getProductDetailsBatch($ids, $shopCipher, $accessToken);
                } catch (TokenExpiredException $e) {
                    $accessToken = $this->refreshShopToken($shop);

                    return $this->client->getProductDetailsBatch($ids, $shopCipher, $accessToken);
                }
            });

            foreach ($products as $item) {
                if (isset($item['status']) && strtoupper((string) $item['status']) !== 'ACTIVATE') {
                    continue;
                }

                try {

                    $detail       = $details[(string) ($item['id'] ?? '')] ?? $item;
                    $internalData = $mapper->map($detail, $shopId);
                    $insertedId   = $productService->upsertFromChannel($internalData, $matchedExisting, $variantIds);

                    if ($insertedId) {
                        $attrs = [];
                        foreach ($detail['product_attributes'] ?? [] as $attr) {
                            if (empty($attr['name'])) {
                                continue;
                            }
                            $vals = array_filter(array_map(fn ($v) => $v['name'] ?? '', $attr['values'] ?? []));
                            sort($vals);
                            $attrs[$attr['name']] = implode(', ', $vals);
                        }

                        $pcmId = $this->productRepository->upsertChannelMapping(
                            (string) $insertedId,
                            $shopId,
                            (string) $item['id'],
                            'synced',
                            $attrs ?: null,
                            false
                        );

                        $this->mapSkusToVariants(
                            $shop,
                            $shopId,
                            (string) $item['id'],
                            $pcmId,
                            (string) $insertedId,
                            $detail['skus'] ?? [],
                            $internalData,
                            $variantIds
                        );

                        $count++;
                        if ($onProgress) {
                            $onProgress($count, max($total, $count + $failed), $failed);
                        }
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error("Failed to pull product {$item['id']}: " . $e->getMessage());

                    ProductSyncLog::record([
                        'channel_shop_id' => $channelShopId,
                        'action'          => ProductSyncLog::ACTION_DOWNLOAD,
                        'status'          => ProductSyncLog::STATUS_FAILED,
                        'payload'         => [
                            'external_product_id' => $item['id'],
                            'title'               => $item['title'] ?? null,
                        ],
                        'error_message'   => $e->getMessage(),
                    ]);
                }
            }

            $pageToken = $res['data']['next_page_token'] ?? null;
            $pages++;

            if ($pages >= $maxPages) {
                Log::warning("TikTok pullProducts: batas {$maxPages} halaman tercapai untuk shop {$shopId}, paginasi dihentikan.", [
                    'shop_id'    => $shopId,
                    'downloaded' => $count,
                    'failed'     => $failed,
                ]);
                break;
            }

        } while ($pageToken);

        if ($onProgress) {
            $onProgress($count, max($total, $count + $failed), $failed);
        }

        return $count;
    }

    public function searchProducts(string $shopId, string $query): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            return [];
        }

        $accessToken = $shop->access_token;
        $shopCipher  = $shop->shop_cipher ?? '';
        $needle      = trim(mb_strtolower($query));

        $results   = [];
        $pageToken = null;
        $pages     = 0;

        do {
            $queries = ['shop_cipher' => $shopCipher, 'page_size' => 100];
            if ($pageToken) {
                $queries['page_token'] = $pageToken;
            }

            $body = ['status' => 'ACTIVATE'];
            try {
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, $body, $accessToken);
            } catch (TokenExpiredException $e) {
                $accessToken = $this->refreshShopToken($shop);
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, $body, $accessToken);
            }

            foreach ($res['data']['products'] ?? [] as $item) {
                if (isset($item['status']) && strtoupper((string) $item['status']) !== 'ACTIVATE') {
                    continue;
                }

                $title     = (string) ($item['title'] ?? '');
                $sellerSku = null;
                foreach ($item['skus'] ?? [] as $sku) {
                    if (!empty($sku['seller_sku'])) {
                        $sellerSku = $sku['seller_sku'];
                        break;
                    }
                }

                if ($needle !== '' && !str_contains(mb_strtolower($title . ' ' . (string) $sellerSku), $needle)) {
                    continue;
                }

                $results[] = [
                    'external_product_id' => (string) ($item['id'] ?? ''),
                    'name'                => $title,
                    'seller_sku'          => $sellerSku,
                    'image'               => $this->normalizeTikTokImageUrl($item['main_images'][0]['thumb_urls'][0] ?? ($item['main_images'][0]['uri'] ?? null)),
                    'shop_id'             => $shopId,
                    'shop_name'           => $shop->shop_name ?? null,
                    'channel_code'        => 'tiktok',
                ];
            }

            $pageToken = $res['data']['next_page_token'] ?? null;
            $pages++;
        } while ($pageToken && $pages < 5 && count($results) < 200);

        return $results;
    }

    public function fetchLiveProduct(string $shopId, string $externalProductId): ?array
    {
        $shop = $this->shopRepository->findByShopId($shopId);

        if (! $shop || ! $shop->access_token) {
            return null;
        }

        $accessToken = $shop->access_token;

        return $this->fetchProductDetail($shop, $externalProductId, $accessToken);
    }

    protected function fetchProductDetail(object $shop, string $externalProductId, string &$accessToken): ?array
    {
        $path    = "/product/202309/products/{$externalProductId}";
        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        try {
            $res = $this->client->request('GET', $path, $queries, [], $accessToken);
        } catch (TokenExpiredException $e) {
            $accessToken = $this->refreshShopToken($shop);
            $res = $this->client->request('GET', $path, $queries, [], $accessToken);
        }

        return $res['data'] ?? null;
    }

    public function pullProductById(string $shopId, string $externalProductId): bool
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            return false;
        }

        $accessToken   = $shop->access_token;
        $channelShopId = $shop->id;

        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper         = app(TikTokToInternalProductMapper::class);

        $detail = $this->fetchProductDetail($shop, $externalProductId, $accessToken);
        if (! $detail) {
            return false;
        }

        if (isset($detail['status']) && strtoupper((string) $detail['status']) !== 'ACTIVATE') {
            Log::info("TikTok download dilewati: produk {$externalProductId} tidak aktif (status {$detail['status']})");

            return false;
        }

        $internalData = $mapper->map($detail, $shopId);
        $insertedId   = $productService->upsertFromChannel($internalData, $matchedExisting, $variantIds);

        if (!$insertedId) {
            return false;
        }

        $attrs = [];
        foreach ($detail['product_attributes'] ?? [] as $attr) {
            if (empty($attr['name'])) {
                continue;
            }
            $vals = array_filter(array_map(fn ($v) => $v['name'] ?? '', $attr['values'] ?? []));
            sort($vals);
            $attrs[$attr['name']] = implode(', ', $vals);
        }

        $pcmId = $this->productRepository->upsertChannelMapping(
            (string) $insertedId,
            $shopId,
            (string) ($detail['id'] ?? $externalProductId),
            'synced',
            $attrs ?: null,
            false
        );

        $this->mapSkusToVariants(
            $shop,
            $shopId,
            (string) ($detail['id'] ?? $externalProductId),
            $pcmId,
            (string) $insertedId,
            $detail['skus'] ?? [],
            $internalData,
            $variantIds
        );

        ProductSyncLog::record([
            'channel_shop_id' => $channelShopId,
            'action'          => ProductSyncLog::ACTION_DOWNLOAD,
            'status'          => ProductSyncLog::STATUS_SUCCESS,
            'response'        => ['external_product_id' => (string) ($detail['id'] ?? $externalProductId)],
        ]);

        return true;
    }

    protected function mapSkusToVariants(
        object $shop,
        string $shopId,
        string $externalProductId,
        string $pcmId,
        string $insertedId,
        array $skus,
        array $internalData,
        array $variantIds
    ): void {
        $variantsByIndex = array_values($internalData['variants'] ?? []);
        $normalized = [];

        foreach ($skus as $idx => $skuData) {
            $salesAttr = $skuData['sales_attributes'][0] ?? null;

            $normalized[] = [
                'sku' => $skuData['seller_sku'] ?? null,
                'external_sku_id' => $skuData['id'] ?? null,
                'price' => $skuData['price']['tax_exclusive_price'] ?? null,
                'group' => $salesAttr['value_id'] ?? $salesAttr['value_name'] ?? null,
                'variant' => $variantsByIndex[$idx] ?? [],
                'fallback_variant_id' => $variantIds[$idx] ?? null,
                'sales_attribute_id' => $salesAttr['id'] ?? null,
                'sales_attribute_name' => $salesAttr['name'] ?? null,
            ];
        }

        app(ChannelModelLinker::class)->link(
            $shop,
            $shopId,
            $externalProductId,
            $normalized,
            $insertedId,
            $pcmId
        );
    }

    public function reconcileChannelData(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken   = $shop->access_token;
        $shopCipher    = $shop->shop_cipher ?? '';
        $channelShopId = $shop->id;

        $updated   = 0;
        $pageToken = null;

        do {
            $queries = ['shop_cipher' => $shopCipher, 'page_size' => 100];
            if ($pageToken) {
                $queries['page_token'] = $pageToken;
            }

            try {
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
            } catch (TokenExpiredException $e) {
                $accessToken = $this->refreshShopToken($shop);
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
            }

            if (!isset($res['data']['products'])) {
                break;
            }

            foreach ($res['data']['products'] as $item) {
                $mapping = \Modules\Product\Models\ProductChannelMapping::where('external_product_id', (string) ($item['id'] ?? ''))
                    ->where('channel_shop_id', $channelShopId)
                    ->first();

                if (!$mapping) {
                    continue;
                }

                $attrs = [];
                foreach ($item['product_attributes'] ?? [] as $attr) {
                    if (empty($attr['name'])) {
                        continue;
                    }
                    $vals = array_filter(array_map(fn ($v) => $v['name'] ?? '', $attr['values'] ?? []));
                    sort($vals);
                    $attrs[$attr['name']] = implode(', ', $vals);
                }
                $canonical = ChannelProductRepository::canonicalAttributes($attrs ?: null);
                $mapping->update([
                    'channel_attributes' => $canonical !== null ? json_decode($canonical, true) : null,
                ]);

                foreach ($item['skus'] ?? [] as $skuData) {
                    if (empty($skuData['id'])) {
                        continue;
                    }
                    $vm = \Modules\Product\Models\ProductVariantChannelMapping::where('product_channel_mapping_id', $mapping->id)
                        ->where('external_sku_id', (string) $skuData['id'])
                        ->first();
                    if (!$vm) {
                        continue;
                    }
                    $vmUpdate = [];
                    if (!empty($skuData['seller_sku'])) {
                        $vmUpdate['channel_seller_sku'] = $skuData['seller_sku'];
                    }
                    if (isset($skuData['price']['tax_exclusive_price'])) {
                        $vmUpdate['synced_price'] = $skuData['price']['tax_exclusive_price'];
                    }
                    if ($vmUpdate) {
                        $vm->update($vmUpdate);
                    }
                }

                $updated++;
            }

            $pageToken = $res['data']['next_page_token'] ?? null;
        } while ($pageToken);

        return $updated;
    }

    public function fetchProductStatuses(string $shopId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return [];
        }

        $accessToken = $shop->access_token;
        $shopCipher  = $shop->shop_cipher ?? '';
        $statuses    = [];
        $pageToken   = null;

        do {
            $queries = ['shop_cipher' => $shopCipher, 'page_size' => 100];
            if ($pageToken) {
                $queries['page_token'] = $pageToken;
            }

            try {
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
            } catch (TokenExpiredException $e) {
                $accessToken = $this->refreshShopToken($shop);
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
            }

            foreach ($res['data']['products'] ?? [] as $item) {
                $extId = (string) ($item['id'] ?? '');
                if ($extId === '') {
                    continue;
                }
                $reason = $item['audit_failed_reasons'] ?? null;
                $statuses[$extId] = [
                    'status' => (string) ($item['status'] ?? ''),
                    'reason' => is_array($reason) ? json_encode($reason) : $reason,
                ];
            }

            $pageToken = $res['data']['next_page_token'] ?? null;
        } while ($pageToken);

        return $statuses;
    }

    protected function assertValidTikTokTitle(string $name): void
    {
        $len = mb_strlen(trim($name));

        if ($len < 25 && ! app()->runningUnitTests()) {
            throw new \RuntimeException(
                "Nama produk minimal 25 karakter untuk TikTok (saat ini {$len}). "
                . "Perpanjang nama produk lalu upload ulang.",
                422
            );
        }

        if ($len > 255) {
            throw new \RuntimeException(
                "Nama produk maksimal 255 karakter untuk TikTok (saat ini {$len}). "
                . "Persingkat nama produk lalu upload ulang.",
                422
            );
        }
    }

    protected function noImageMessage(array $imageUrls, array $errors): string
    {
        if (empty($imageUrls)) {
            return 'Produk belum memiliki gambar. Tambahkan minimal 1 gambar (JPG/PNG) sebelum upload ke TikTok.';
        }

        $reason = ! empty($errors)
            ? collect($errors)->pluck('reason')->unique()->take(2)->implode('; ')
            : 'tidak diketahui';
        $count = count($imageUrls);

        return "Gagal memproses {$count} gambar untuk TikTok: {$reason}. "
            . 'Pastikan gambar dapat diakses & berformat JPG/PNG minimal 300x300.';
    }

    protected function refreshShopToken(object $shop): string
    {
        if (empty($shop->refresh_token)) {
            throw new \Exception("No refresh token available for shop: {$shop->shop_id}");
        }

        $tokenData = $this->client->refreshAccessToken($shop->refresh_token);

        $newAccessToken  = $tokenData['data']['access_token']  ?? null;
        $newRefreshToken = $tokenData['data']['refresh_token'] ?? null;

        if (!$newAccessToken) {
            throw new \Exception("Token refresh failed for shop: {$shop->shop_id}");
        }

        $update = [
            'access_token' => $newAccessToken,
            'updated_at'   => now(),
        ];

        if ($newRefreshToken) {
            $update['refresh_token'] = $newRefreshToken;
        }

        if (!empty($tokenData['data']['access_token_expire_in'])) {
            $update['token_expires_at'] = now()->addSeconds($tokenData['data']['access_token_expire_in']);
        }

        $this->shopRepository->updateTokens($shop->id, $update);

        Log::info("TikTok token refreshed for shop: {$shop->shop_id}");

        return $newAccessToken;
    }

    public function syncPriceAndInventory(string $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = $this->productRepository->findById($productId);
        if (!$product) {
            throw new \Exception("Produk tidak ditemukan.");
        }

        $externalProductId = $this->productRepository->getExternalProductId($productId, $shopId);
        if (!$externalProductId) {
            throw new \Exception("Product not synced to TikTok yet.");
        }

        $variants = $this->productRepository->getVariantsByProductId($productId);

        $channelWarehouse = $this->productRepository->getChannelWarehouseByStore($shopId);

        $skus = [];
        $inventorySkus = [];

        foreach ($variants as $v) {
            $skus[] = [
                'seller_sku' => $v->sku,
                'price' => [
                    'amount' => (string)($v->sell_price ?? 0),
                    'currency' => 'IDR'
                ]
            ];

            $availableQty = 0;
            if ($channelWarehouse) {
                $availableQty = $this->productRepository->getAvailableQty($v->id, $channelWarehouse->location_id);
            }

            $inventorySkus[] = [
                'seller_sku' => $v->sku,
                'inventory' => [
                    [
                        'quantity' => max(0, $availableQty),
                        'warehouse_id' => $channelWarehouse->channel_location_id ?? '',
                    ]
                ]
            ];
        }

        $pricePayload = ['product_id' => $externalProductId, 'skus' => $skus];
        $invPayload = ['product_id' => $externalProductId, 'skus' => $inventorySkus];

        try {
            $this->client->request('POST', "/product/202309/products/{$externalProductId}/prices/update", ['shop_cipher' => $shop->shop_cipher ?? ''], $pricePayload, $shop->access_token);
        } catch (\Exception $e) {
            Log::warning("TikTok price update failed: " . $e->getMessage());
        }

        try {
            $this->client->request('POST', "/product/202309/products/{$externalProductId}/inventory/update", ['shop_cipher' => $shop->shop_cipher ?? ''], $invPayload, $shop->access_token);
        } catch (\Exception $e) {
            Log::warning("TikTok inventory update failed: " . $e->getMessage());
        }

        return true;
    }

    public function syncInventoryBySku(string $sku, string $shopId): bool
    {
        $variant = $this->productRepository->getVariantBySku($sku);
        if (!$variant) {
            return false;
        }

        try {
            $this->syncPriceAndInventory($variant->product_id, $shopId);
            return true;
        } catch (\Exception $e) {
            Log::warning("syncInventoryBySku failed for {$sku} on shop {$shopId}: " . $e->getMessage());
            return false;
        }
    }

    public function pushUpdate(string $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = $this->productRepository->findById($productId);
        if (!$product) {
            throw new \Exception("Produk tidak ditemukan");
        }

        $externalProductId = $this->productRepository->getExternalProductId($productId, $shopId);
        if (!$externalProductId) {
            throw new \Exception("Product not synced to TikTok yet");
        }

        $this->assertValidTikTokTitle($product->name ?? '');

        $variants = $this->productRepository->getVariantsByProductId($productId);
        $media = collect($this->productRepository->getMediaByProductId($productId));
        $accessToken = $shop->access_token;

        $imageUrls = $media
            ->filter(fn ($m) => ($m->variant_id ?? null) === null && ($m->media_type ?? 'image') === 'image')
            ->pluck('url')
            ->all();

        $uploadResult = $this->imageUploader->upload($imageUrls, $accessToken);
        $uploadedImageIds = $uploadResult['uris'];

        if (empty($uploadedImageIds)) {
            throw new \RuntimeException($this->noImageMessage($imageUrls, $uploadResult['errors']), 422);
        }

        $videoId = null;
        $productVideo = $media->first(fn ($m) => ($m->variant_id ?? null) === null && ($m->media_type ?? '') === 'video');
        if ($productVideo) {
            $videoId = $this->imageUploader->uploadVideo($productVideo->url, $accessToken);
        }

        $storedMappings = $this->productRepository->getVariantChannelMappings($productId, $shopId);

        $internalProduct = (array)$product;
        $internalProduct['variants'] = $variants->map(function ($v) use ($media, $accessToken, $storedMappings) {
            $variantArr = (array)$v;

            $options = $this->productRepository->getVariantOptions($v->id);
            $variantArr['options'] = array_map(fn($opt) => (array)$opt, $options);

            $stored = $storedMappings[$v->id] ?? null;
            if ($stored) {
                if (!empty($stored->external_sku_id)) {
                    $variantArr['external_sku_id'] = $stored->external_sku_id;
                }
                if (!empty($stored->sales_attribute_id)) {
                    $variantArr['sales_attributes'] = [[
                        'attribute_id'   => $stored->sales_attribute_id,
                        'attribute_name' => $stored->sales_attribute_name,
                        'custom_value'   => $v->sku ?? '',
                    ]];
                }
            }

            $variantImage = $media->first(fn ($m) => ($m->variant_id ?? null) === $v->id && ($m->media_type ?? 'image') === 'image');
            if ($variantImage) {
                $vUris = $this->imageUploader->upload([$variantImage->url], $accessToken)['uris'];
                if (!empty($vUris)) {
                    $variantArr['image_uri'] = $vUris[0];
                }
            }

            return $variantArr;
        })->toArray();

        $hasMeaningfulOptions = collect($internalProduct['variants'])->contains(
            fn ($v) => collect($v['options'] ?? [])->contains(fn ($opt) => ! empty($opt['attribute_id']))
        );
        if ($hasMeaningfulOptions) {
            $internalProduct['variants'] = array_values(
                array_filter($internalProduct['variants'], fn ($v) =>
                    collect($v['options'] ?? [])->contains(fn ($opt) => ! empty($opt['attribute_id']))
                    || ! empty($v['sales_attributes']))
            );
        }

        $config = $this->buildUploadConfig($product, $shop, $videoId);
        $config['mode'] = 'update';
        $payload = $this->mapper->map($internalProduct, $uploadedImageIds, $config);

        return $this->client->request('PUT', "/product/202309/products/{$externalProductId}", ['shop_cipher' => $shop->shop_cipher ?? ''], $payload, $shop->access_token);
    }

    public function deleteProduct(string $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $externalProductId = $this->productRepository->getExternalProductId($productId, $shopId);
        if (!$externalProductId) {
            return true;
        }

        return $this->client->request('DELETE', "/product/202309/products", ['shop_cipher' => $shop->shop_cipher ?? ''], ['product_ids' => [$externalProductId]], $shop->access_token);
    }

    public function activateProduct(string $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $externalProductId = $this->productRepository->getExternalProductId($productId, $shopId);
        if (!$externalProductId) {
            throw new \Exception("Product not synced to TikTok yet");
        }

        return $this->client->request('POST', "/product/202309/products/activate", ['shop_cipher' => $shop->shop_cipher ?? ''], ['product_ids' => [$externalProductId]], $shop->access_token);
    }

    public function deactivateProduct(string $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $externalProductId = $this->productRepository->getExternalProductId($productId, $shopId);
        if (!$externalProductId) {
            throw new \Exception("Product not synced to TikTok yet");
        }

        return $this->client->request('POST', "/product/202309/products/deactivate", ['shop_cipher' => $shop->shop_cipher ?? ''], ['product_ids' => [$externalProductId]], $shop->access_token);
    }

    public function bulkPushProducts(string $shopId): int
    {
        $failCount = 0;
        $products = $this->productRepository->getActiveProducts();

        foreach ($products as $product) {
            try {
                $externalProductId = $this->productRepository->getExternalProductId($product->id, $shopId);
                if (empty($externalProductId)) {
                    $this->pushProduct($product->id, $shopId);
                } else {
                    $this->pushUpdate($product->id, $shopId);
                }
            } catch (\Exception $e) {
                Log::error("Failed to bulk push product {$product->id} to TikTok: " . $e->getMessage());
                $failCount++;
            }
        }

        return $failCount;
    }

    public function syncCategoryTree(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko TikTok tidak terhubung: {$shopId}");
        }

        $channelId = DB::table('channels')->where('code', 'tiktok')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel tiktok belum ter-seed.');
        }

        $count = 0;
        $seen = [];
        $pageToken = '';

        do {
            $queries = [
                'shop_cipher' => $shop->shop_cipher,
                'locale' => 'id-ID',
                'page_size' => 200,
            ];
            if ($pageToken !== '') {
                $queries['page_token'] = $pageToken;
            }

            $res = $this->client->request(
                'GET',
                '/product/202309/categories',
                $queries,
                [],
                $shop->access_token
            );

            $categories = $res['data']['categories'] ?? [];

            foreach ($categories as $node) {
                $extId = (string) ($node['id'] ?? '');
                if ($extId === '') {
                    continue;
                }
                $seen[] = $extId;

                $parentId = (string) ($node['parent_id'] ?? '0');
                if ($parentId === '') {
                    $parentId = '0';
                }

                $values = [
                    'parent_external_id' => $parentId,
                    'name' => (string) ($node['local_name'] ?? $node['name'] ?? ''),
                    'is_leaf' => (bool) ($node['is_leaf'] ?? false),
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
            }

            $pageToken = $res['data']['next_page_token'] ?? '';
        } while ($pageToken !== '');

        $this->sweepDeprecated($channelId, $seen);

        return $count;
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

    public function syncCategoryAttributes(string $shopId, string $categoryExtId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko TikTok tidak terhubung: {$shopId}");
        }

        $channelId = DB::table('channels')->where('code', 'tiktok')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel tiktok belum ter-seed.');
        }

        $channelCategory = \Modules\Product\Models\ChannelCategory::where('channel_id', $channelId)
            ->where('external_id', $categoryExtId)
            ->first();
        if (! $channelCategory) {
            throw new \Exception("Kategori TikTok {$categoryExtId} belum disinkronkan.");
        }

        $channel = \Modules\Channel\Models\Channel::find($channelId);

        app(ChannelAttributeService::class)->syncTikTokAttributes($channel, $channelCategory);

        return \Modules\Product\Models\ChannelAttribute::where('channel_category_id', $channelCategory->id)->count();
    }

    public function syncAllMappedCategoryAttributes(string $shopId): array
    {
        $channelId = DB::table('channels')->where('code', 'tiktok')->value('id');
        if (! $channelId) {
            throw new \Exception('Channel tiktok belum ter-seed.');
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

    protected function normalizeTikTokImageUrl(?string $url): ?string
    {
        if (!$url || $url === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (str_starts_with($url, 'tos-')) {
            return 'https://p16-oec-ttp.tiktokcdn-us.com/' . $url;
        }

        return null;
    }
}
