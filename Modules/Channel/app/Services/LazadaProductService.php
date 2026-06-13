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
