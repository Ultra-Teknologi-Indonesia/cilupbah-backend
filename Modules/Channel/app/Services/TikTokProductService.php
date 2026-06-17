<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Channel\Services\TikTokToInternalOrderMapper;
use Modules\Channel\Services\TikTokToInternalProductMapper;
use Modules\Product\Models\ProductSyncLog;

class TikTokProductService
{
    protected TikTokClient $client;
    protected TikTokProductMapper $mapper;
    protected ChannelShopRepository $shopRepository;
    protected ChannelProductRepository $productRepository;
    protected TikTokImageUploader $imageUploader;

    public function __construct(
        TikTokClient $client,
        TikTokProductMapper $mapper,
        ChannelShopRepository $shopRepository,
        ChannelProductRepository $productRepository,
        TikTokImageUploader $imageUploader
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->shopRepository = $shopRepository;
        $this->productRepository = $productRepository;
        $this->imageUploader = $imageUploader;
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
            throw new \Exception("Product not found");
        }

        $variants = $this->productRepository->getVariantsByProductId($productId);
        $media = collect($this->productRepository->getMediaByProductId($productId));

        // Foto LEVEL PRODUK (variant_id null) → main_images (di-cap 9 di mapper).
        $imageUrls = $media
            ->filter(fn ($m) => ($m->variant_id ?? null) === null && $m->media_type === 'image')
            ->pluck('url')
            ->all();

        $uploadResult = $this->imageUploader->upload($imageUrls, $accessToken);
        $uploadedImageIds = $uploadResult['uris'];

        // Pre-flight: TikTok mewajibkan main_images. Gagal cepat dengan pesan
        // jelas daripada kena error mentah "MainImages is a required field".
        if (empty($uploadedImageIds)) {
            throw new \RuntimeException($this->noImageMessage($imageUrls, $uploadResult['errors']), 422);
        }

        // Video produk (opsional, 1 per produk).
        $videoId = null;
        $productVideo = $media->first(fn ($m) => ($m->variant_id ?? null) === null && $m->media_type === 'video');
        if ($productVideo) {
            $videoId = $this->imageUploader->uploadVideo($productVideo->url, $accessToken);
        }

        $internalProduct = (array)$product;
        $internalProduct['variants'] = $variants->map(function ($v) use ($media, $accessToken) {
            $variantArr = (array)$v;
            $options = $this->productRepository->getVariantOptions($v->id);
            $variantArr['options'] = array_map(fn($opt) => (array)$opt, $options);

            // Foto per-varian (pertama) → sku_img (non-fatal bila gagal).
            $variantImage = $media->first(fn ($m) => ($m->variant_id ?? null) === $v->id && $m->media_type === 'image');
            if ($variantImage) {
                $vUris = $this->imageUploader->upload([$variantImage->url], $accessToken)['uris'];
                if (!empty($vUris)) {
                    $variantArr['image_uri'] = $vUris[0];
                }
            }

            return $variantArr;
        })->toArray();

        $tiktokCategoryId = null;
        if (!empty($product->category_id)) {
            $tiktokCategoryId = $this->productRepository->getChannelCategoryExternalId($product->category_id, $shop->channel_id);
        }

        $config = [];
        if ($tiktokCategoryId) {
            $config['category_id'] = $tiktokCategoryId;
        }
        if ($videoId) {
            $config['video_id'] = $videoId;
        }

        $specs = $this->productRepository->getProductSpecifications($productId);

        $mappedAttributes = [];
        foreach ($specs as $spec) {

            $mapping = $this->productRepository->getAttributeChannelMapping($spec->attribute_id);

            if ($mapping) {
                $channelAttr = $this->productRepository->getChannelAttribute($mapping->channel_attribute_id);

                if ($channelAttr) {
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
            }
        }

        if (!empty($mappedAttributes)) {
            $config['attributes'] = $mappedAttributes;
        }

        $payload = $this->mapper->map($internalProduct, $uploadedImageIds, $config);

        $res = $this->client->request('POST', '/product/202309/products', ['shop_cipher' => $shopCipher], $payload, $accessToken);

        if (isset($res['data']['product_id'])) {
            $this->productRepository->updateChannelProductId($productId, $res['data']['product_id'], $shopId);
        }

        return $res;
    }

    public function pullProducts(string $shopId): int
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
                try {
                    $internalData = $mapper->map($item, $shopId);
                    $insertedId   = $productService->upsertFromChannel($internalData);

                    if ($insertedId) {
                        $attrs = [];
                        foreach ($item['product_attributes'] ?? [] as $attr) {
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
                            $attrs ?: null
                        );

                        foreach ($item['skus'] ?? [] as $skuData) {
                            $sku = !empty($skuData['seller_sku'])
                                ? $skuData['seller_sku']
                                : ('TK-' . $skuData['id']);

                            $variant = $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku);

                            if ($variant) {
                                $this->productRepository->upsertVariantChannelMapping(
                                    $pcmId,
                                    $variant->id,
                                    $skuData['id'] ?? null,
                                    $skuData['seller_sku'] ?? null,
                                    $skuData['price']['tax_exclusive_price'] ?? null
                                );
                            }
                        }

                        $count++;
                    }
                } catch (\Exception $e) {
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

        } while ($pageToken);

        return $count;
    }

    /**
     * Cari produk di TikTok (by SKU/nama). NON-DESTRUKTIF: hanya membaca, tidak
     * menyentuh master. Mengembalikan DTO ringan untuk modal "Download Satuan".
     */
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

            try {
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
            } catch (TokenExpiredException $e) {
                $accessToken = $this->refreshShopToken($shop);
                $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
            }

            foreach ($res['data']['products'] ?? [] as $item) {
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
                    'image'               => $item['main_images'][0]['thumb_urls'][0] ?? ($item['main_images'][0]['uri'] ?? null),
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

    /**
     * Download satu produk TikTok by external id (Download Satuan). Mirror
     * pullProducts untuk satu item; status produk → 'download'.
     */
    public function pullProductById(string $shopId, string $externalProductId): bool
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            return false;
        }

        $accessToken   = $shop->access_token;
        $shopCipher    = $shop->shop_cipher ?? '';
        $channelShopId = $shop->id;

        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper         = app(TikTokToInternalProductMapper::class);

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

            foreach ($res['data']['products'] ?? [] as $item) {
                if ((string) ($item['id'] ?? '') !== (string) $externalProductId) {
                    continue;
                }

                $internalData = $mapper->map($item, $shopId);
                $insertedId   = $productService->upsertFromChannel($internalData);

                if (!$insertedId) {
                    return false;
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

                $pcmId = $this->productRepository->upsertChannelMapping(
                    (string) $insertedId,
                    $shopId,
                    (string) $item['id'],
                    'synced',
                    $attrs ?: null
                );

                foreach ($item['skus'] ?? [] as $skuData) {
                    $sku = !empty($skuData['seller_sku'])
                        ? $skuData['seller_sku']
                        : ('TK-' . ($skuData['id'] ?? ''));

                    $variant = $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku);

                    if ($variant) {
                        $this->productRepository->upsertVariantChannelMapping(
                            $pcmId,
                            $variant->id,
                            $skuData['id'] ?? null,
                            $skuData['seller_sku'] ?? null,
                            $skuData['price']['tax_exclusive_price'] ?? null
                        );
                    }
                }

                ProductSyncLog::record([
                    'channel_shop_id' => $channelShopId,
                    'action'          => ProductSyncLog::ACTION_DOWNLOAD,
                    'status'          => ProductSyncLog::STATUS_SUCCESS,
                    'response'        => ['external_product_id' => (string) $item['id']],
                ]);

                return true;
            }

            $pageToken = $res['data']['next_page_token'] ?? null;
        } while ($pageToken);

        return false;
    }

    /**
     * Rekonsiliasi NON-DESTRUKTIF: tarik produk dari channel lalu update HANYA
     * kolom mapping channel (atribut, seller_sku, harga) untuk listing yang
     * sudah termapping. Tidak menyentuh master (tak panggil upsertFromChannel).
     */
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
                $canonical = \Modules\Channel\Repositories\ChannelProductRepository::canonicalAttributes($attrs ?: null);
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

    /**
     * Pesan jelas saat tak ada gambar yang berhasil diunggah ke TikTok
     * (menggantikan error mentah "MainImages is a required field").
     *
     * @param array<int, string> $imageUrls
     * @param array<int, array{url: string, reason: string}> $errors
     */
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
            throw new \Exception("Product not found.");
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
            throw new \Exception("Product not found");
        }

        $externalProductId = $this->productRepository->getExternalProductId($productId, $shopId);
        if (!$externalProductId) {
            throw new \Exception("Product not synced to TikTok yet");
        }

        $variants = $this->productRepository->getVariantsByProductId($productId);
        $media = collect($this->productRepository->getMediaByProductId($productId));
        $accessToken = $shop->access_token;

        // Foto LEVEL PRODUK → main_images (di-cap 9 di mapper).
        $imageUrls = $media
            ->filter(fn ($m) => ($m->variant_id ?? null) === null && ($m->media_type ?? 'image') === 'image')
            ->pluck('url')
            ->all();

        // Gambar harus diunggah dulu ke TikTok (butuh URI, bukan URL mentah).
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

        $internalProduct = (array)$product;
        $internalProduct['variants'] = $variants->map(function ($v) use ($media, $accessToken) {
            $variantArr = (array)$v;
            $options = $this->productRepository->getRawVariantOptions($v->id);
            $variantArr['options'] = array_map(fn($opt) => (array)$opt, $options);

            $variantImage = $media->first(fn ($m) => ($m->variant_id ?? null) === $v->id && ($m->media_type ?? 'image') === 'image');
            if ($variantImage) {
                $vUris = $this->imageUploader->upload([$variantImage->url], $accessToken)['uris'];
                if (!empty($vUris)) {
                    $variantArr['image_uri'] = $vUris[0];
                }
            }

            return $variantArr;
        })->toArray();

        $config = $videoId ? ['video_id' => $videoId] : [];
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
}
