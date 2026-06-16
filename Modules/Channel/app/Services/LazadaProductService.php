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
    public function __construct(
        protected LazadaClient $client,
        protected LazadaToInternalProductMapper $inboundMapper,
        protected ChannelShopRepository $shopRepository,
        protected ChannelProductRepository $productRepository,
        protected LazadaAuthService $authService,
    ) {}

    /**
     * Tarik status listing terkini dari Lazada (untuk polling status review).
     * @return array<string, array{status:string, reason:?string}> keyed by external product id (item_id)
     */
    public function fetchProductStatuses(string $shopId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return [];
        }

        $statuses = [];
        $offset = 0;
        $limit = 50;

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

    /**
     * Tarik pohon kategori Lazada → upsert ke channel_categories.
     * Prasyarat agar PrimaryCategory (pemetaan kategori) bisa di-resolve saat push.
     */
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
        $walk = function (array $list, string $parent) use (&$walk, &$count, $channelId) {
            foreach ($list as $node) {
                $extId = (string) ($node['category_id'] ?? '');
                if ($extId === '') {
                    continue;
                }

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

        return $count;
    }

    /**
     * Tarik spec atribut Lazada untuk satu kategori (leaf) → upsert ke
     * channel_attributes + channel_attribute_options. Prasyarat pre-flight
     * validator (memeriksa atribut wajib sebelum push).
     *
     * @param  string  $categoryExtId  external_id kategori Lazada (channel_categories.external_id)
     * @return int Jumlah atribut yang disinkronkan.
     */
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
            // Lazada mengidentifikasi atribut via "name" (label = tampilan).
            $extId = (string) ($attr['name'] ?? '');
            if ($extId === '') {
                continue;
            }

            $inputType = strtolower((string) ($attr['input_type'] ?? ''));

            $values = [
                'name' => (string) ($attr['label'] ?? $attr['name'] ?? ''),
                'is_required' => (bool) ($attr['is_mandatory'] ?? false),
                'is_multiple' => in_array($inputType, ['multiselect', 'multienuminput', 'checkbox'], true),
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

            // Opsi nilai tertutup (untuk value-mapping varian di Fase 4).
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

    /**
     * Sinkron atribut untuk SEMUA kategori leaf Lazada yang sudah dipetakan
     * dari kategori internal (category_channel_mappings). Mengembalikan
     * [external_id => jumlah_atribut].
     *
     * @return array<string, int>
     */
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
     * Tarik & sinkron SATU produk Lazada (untuk webhook Product Edited / Shallow Stock).
     * Mengembalikan true bila berhasil di-upsert.
     */
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

        $productService = app(\Modules\Product\Services\ProductService::class);

        try {
            $internalData = $this->inboundMapper->map($item, $shopId);
            $insertedId = $productService->upsertFromChannel($internalData);
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
            'synced'
        );

        return true;
    }

    public function pullProducts(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Lazada tidak ditemukan atau belum terhubung: {$shopId}");
        }

        $productService = app(\Modules\Product\Services\ProductService::class);

        $count = 0;
        $offset = 0;
        $limit = 50;

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
                try {
                    $internalData = $this->inboundMapper->map($item, $shopId);
                    $insertedId = $productService->upsertFromChannel($internalData);

                    if ($insertedId) {
                        $pcmId = $this->productRepository->upsertChannelMapping(
                            (string) $insertedId,
                            $shopId,
                            (string) ($item['item_id'] ?? ''),
                            'synced'
                        );

                        foreach ($item['skus'] ?? [] as $skuData) {
                            $sku = ! empty($skuData['SellerSku'])
                                ? $skuData['SellerSku']
                                : ('LZ-' . ($skuData['SkuId'] ?? ''));

                            $variant = DB::table('product_variants')
                                ->where('product_id', $insertedId)
                                ->where('sku', $sku)
                                ->first();

                            if ($variant) {
                                $this->productRepository->upsertVariantChannelMapping(
                                    $pcmId,
                                    $variant->id,
                                    isset($skuData['SkuId']) ? (string) $skuData['SkuId'] : null
                                );
                            }
                        }

                        $count++;
                    }
                } catch (\Throwable $e) {
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
        } while (count($products) === $limit);

        return $count;
    }
}
