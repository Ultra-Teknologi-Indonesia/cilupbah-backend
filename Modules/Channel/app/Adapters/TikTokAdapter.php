<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokImageUploader;
use Modules\Channel\Services\TikTokProductMapper;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Services\TikTokToInternalProductMapper;
use Modules\Product\Models\Product;

class TikTokAdapter implements MarketplaceAdapterInterface
{
    protected TikTokClient $client;
    protected TikTokProductMapper $outboundMapper;
    protected TikTokToInternalProductMapper $inboundMapper;
    protected TikTokImageUploader $imageUploader;
    protected TikTokProductService $productService;
    protected ChannelStockResolver $stockResolver;

    public function __construct(
        TikTokClient $client,
        TikTokProductMapper $outboundMapper,
        TikTokToInternalProductMapper $inboundMapper,
        TikTokImageUploader $imageUploader,
        TikTokProductService $productService,
        ChannelStockResolver $stockResolver
    ) {
        $this->client = $client;
        $this->outboundMapper = $outboundMapper;
        $this->inboundMapper = $inboundMapper;
        $this->imageUploader = $imageUploader;
        $this->productService = $productService;
        $this->stockResolver = $stockResolver;
    }

    public function getChannelCode(): string
    {
        return 'tiktok';
    }

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array
    {
        $images = $product->media->where('media_type', 'image');

        $productImageUrls = $images->whereNull('variant_id')->sortBy('sort_order')->pluck('url')->values()->all();
        if (empty($productImageUrls)) {
            $productImageUrls = $images->sortBy('sort_order')->pluck('url')->values()->all();
        }
        $imageUris = empty($productImageUrls) ? [] : $this->imageUploader->uploadFromUrls($productImageUrls, $shop->access_token);

        $variantUriById = [];
        foreach ($images->whereNotNull('variant_id')->sortBy('sort_order')->groupBy('variant_id') as $variantId => $group) {
            $url = $group->first()->url ?? null;
            if (! $url) {
                continue;
            }
            $uri = $this->imageUploader->uploadFromUrls([$url], $shop->access_token)[0] ?? null;
            if ($uri) {
                $variantUriById[$variantId] = $uri;
            }
        }

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $internalProductArray = $product->toArray();
        $internalProductArray['variants'] = $product->variants->map(function ($variant) use ($variantUriById, $stockByVariant) {
            $arr = $variant->toArray();
            if (! empty($variantUriById[$variant->id])) {
                $arr['image_uri'] = $variantUriById[$variant->id];
            }
            $arr['stock'] = $stockByVariant[$variant->id] ?? 0;

            return $arr;
        })->all();

        $config = $this->productService->buildUploadConfig($product, $shop, null, $attributeMapping);

        $payload = $this->outboundMapper->map($internalProductArray, $imageUris, $config);

        try {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $res = $this->client->request('POST', '/product/202309/products', $queries, $payload, $shop->access_token);

            if (!empty($res['data']['product_id'])) {
                return [
                    'success' => true,
                    'external_product_id' => (string) $res['data']['product_id'],
                    'message' => 'Produk berhasil didorong',
                    'skus' => $res['data']['skus'] ?? []
                ];
            }

            return [
                'success' => false,
                'message' => 'Gagal mendorong produk: ' . json_encode($res),
            ];
        } catch (\Exception $e) {
            Log::error("TikTok pushProduct error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $images = $product->media->where('media_type', 'image');

        $productImageUrls = $images->whereNull('variant_id')->sortBy('sort_order')->pluck('url')->values()->all();
        if (empty($productImageUrls)) {
            $productImageUrls = $images->sortBy('sort_order')->pluck('url')->values()->all();
        }
        $imageUris = empty($productImageUrls) ? [] : $this->imageUploader->uploadFromUrls($productImageUrls, $shop->access_token);

        $variantUriById = [];
        foreach ($images->whereNotNull('variant_id')->sortBy('sort_order')->groupBy('variant_id') as $variantId => $group) {
            $url = $group->first()->url ?? null;
            if (! $url) {
                continue;
            }
            $uri = $this->imageUploader->uploadFromUrls([$url], $shop->access_token)[0] ?? null;
            if ($uri) {
                $variantUriById[$variantId] = $uri;
            }
        }

        $internalProductArray = $product->toArray();
        $internalProductArray['variants'] = $this->buildUpdateVariants($product, $shop, $externalProductId, $variantUriById);

        $config = $this->productService->buildUploadConfig($product, $shop);
        $config['mode'] = 'update';
        $payload = $this->outboundMapper->map($internalProductArray, $imageUris, $config);
        $payload['product_id'] = $externalProductId;

        try {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $res = $this->client->request('PUT', "/product/202309/products/{$externalProductId}", $queries, $payload, $shop->access_token);

            return [
                'success' => true,
                'message' => 'Produk berhasil diperbarui',
                'skus' => $res['data']['skus'] ?? []
            ];
        } catch (\Exception $e) {
            Log::error("TikTok updateProduct error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function buildUpdateVariants(Product $product, ChannelShop $shop, string $externalProductId, array $variantUriById = []): array
    {

        $stored = [];
        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings->first(function ($m) use ($shop) {
                return $m->channelMapping && $m->channelMapping->channel_shop_id === $shop->id;
            });
            if ($mapping) {
                $stored[$variant->sku] = [
                    'external_sku_id'      => $mapping->external_sku_id,
                    'sales_attribute_id'   => $mapping->sales_attribute_id,
                    'sales_attribute_name' => $mapping->sales_attribute_name,
                ];
            }
        }

        $needsBackfill = $product->variants->contains(function ($variant) use ($stored) {
            return empty($stored[$variant->sku]['sales_attribute_id']);
        });

        if ($needsBackfill) {
            $detail = $this->getProductDetail($shop, $externalProductId);
            foreach ($detail['data']['skus'] ?? [] as $remoteSku) {
                $sellerSku = $remoteSku['seller_sku'] ?? null;
                if ($sellerSku === null) {
                    continue;
                }
                $sale = $remoteSku['sales_attributes'][0] ?? null;
                $stored[$sellerSku] = [
                    'external_sku_id'      => $stored[$sellerSku]['external_sku_id'] ?? ($remoteSku['id'] ?? null),
                    'sales_attribute_id'   => is_array($sale) ? ($sale['attribute_id'] ?? $sale['id'] ?? null) : null,
                    'sales_attribute_name' => is_array($sale) ? ($sale['attribute_name'] ?? $sale['name'] ?? null) : null,
                ];
            }
        }

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $variants = [];
        foreach ($product->variants as $variant) {
            $row = $variant->toArray();
            $meta = $stored[$variant->sku] ?? [];

            $row['stock'] = $stockByVariant[$variant->id] ?? 0;

            if (!empty($variantUriById[$variant->id])) {
                $row['image_uri'] = $variantUriById[$variant->id];
            }

            if (!empty($meta['external_sku_id'])) {
                $row['external_sku_id'] = $meta['external_sku_id'];
            }
            if (!empty($meta['sales_attribute_id'])) {
                $row['sales_attributes'] = [[
                    'attribute_id'   => $meta['sales_attribute_id'],
                    'attribute_name' => $meta['sales_attribute_name'] ?? null,
                    'custom_value'   => $variant->sku,
                ]];
            }

            $variants[] = $row;
        }

        return $variants;
    }

    public function getProductDetail(ChannelShop $shop, string $externalProductId): ?array
    {
        try {
            return $this->client->request(
                'GET',
                "/product/202309/products/{$externalProductId}",
                ['shop_cipher' => $shop->shop_cipher ?? ''],
                [],
                $shop->access_token
            );
        } catch (\Exception $e) {
            Log::warning("TikTok getProductDetail error: " . $e->getMessage());
            return null;
        }
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {
        try {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $payload = ['product_ids' => [$externalProductId]];
            $res = $this->client->request('DELETE', '/product/202309/products', $queries, $payload, $shop->access_token);

            return [
                'success' => true,
                'message' => 'Produk berhasil dihapus',
            ];
        } catch (\Exception $e) {
            Log::error("TikTok deleteProduct error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function activateProduct(ChannelShop $shop, string $externalProductId): array
    {
        try {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $payload = ['product_ids' => [$externalProductId]];
            $res = $this->client->request('POST', '/product/202309/products/activate', $queries, $payload, $shop->access_token);

            return [
                'success' => true,
                'message' => 'Produk berhasil diaktifkan',
            ];
        } catch (\Exception $e) {
            Log::error("TikTok activateProduct error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array
    {
        try {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $payload = ['product_ids' => [$externalProductId]];
            $res = $this->client->request('POST', '/product/202309/products/deactivate', $queries, $payload, $shop->access_token);

            return [
                'success' => true,
                'message' => 'Produk berhasil dinonaktifkan',
            ];
        } catch (\Exception $e) {
            Log::error("TikTok deactivateProduct error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $product->loadMissing('variants');
        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $inventorySkus = [];
        $priceSkus = [];

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function($q) use($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if ($mapping && $mapping->external_sku_id) {
                $availableQty = (int) ($stockByVariant[$variant->id] ?? 0);

                $inventorySkus[] = [
                    'id' => $mapping->external_sku_id,
                    'inventory' => [
                        [
                            'warehouse_id' => config('channel.tiktok_defaults.warehouse_id', '7646426075561690887'),
                            'quantity' => max(0, $availableQty),
                        ]
                    ]
                ];

                $priceSkus[] = [
                    'id' => $mapping->external_sku_id,
                    'price' => [
                        'amount' => (string) $variant->sell_price,
                        'currency' => 'IDR'
                    ]
                ];
            }
        }

        if (empty($inventorySkus)) {
            return ['success' => false, 'message' => 'Tidak ada SKU yang terhubung untuk diperbarui'];
        }

        try {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

            $invPayload = ['product_id' => $externalProductId, 'skus' => $inventorySkus];
            $this->client->request('POST', "/product/202309/products/{$externalProductId}/inventory/update", $queries, $invPayload, $shop->access_token);

            $pricePayload = ['product_id' => $externalProductId, 'skus' => $priceSkus];
            $this->client->request('POST', "/product/202309/products/{$externalProductId}/prices/update", $queries, $pricePayload, $shop->access_token);

            return [
                'success' => true,
                'message' => 'Harga dan stok berhasil disinkronisasi',
            ];
        } catch (\Exception $e) {
            Log::error("TikTok syncPriceAndStock error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function mapInboundProduct(array $channelData, string $shopId): array
    {
        return $this->inboundMapper->map($channelData, $shopId);
    }
}
