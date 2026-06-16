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
