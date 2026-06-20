<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\ShopeeClient;
use Modules\Channel\Services\ShopeeMediaUploader;
use Modules\Channel\Services\ShopeeProductMapper;
use Modules\Channel\Services\ShopeeToInternalProductMapper;
use Modules\Product\Models\Product;

/**
 * Adapter produk Shopee (v2). Gambar di-upload ke media space (ShopeeMediaUploader)
 * untuk memperoleh image_id sebelum add_item.
 */
class ShopeeAdapter implements MarketplaceAdapterInterface
{
    public function __construct(
        protected ShopeeClient $client,
        protected ShopeeProductMapper $outboundMapper,
        protected ShopeeToInternalProductMapper $inboundMapper,
        protected ShopeeMediaUploader $mediaUploader,
        protected ChannelStockResolver $stockResolver,
    ) {}

    public function getChannelCode(): string
    {
        return 'shopee';
    }

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array
    {
        try {
            $payload = $this->buildProductPayload($product, $shop);
            $res = $this->client->request('POST', '/api/v2/product/add_item', $payload, $shop->access_token, $shop->shop_id);

            $itemId = $res['response']['item_id'] ?? null;

            if ($itemId) {
                return [
                    'success' => true,
                    'external_product_id' => (string) $itemId,
                    'message' => 'Produk berhasil didorong ke Shopee',
                    'skus' => $this->normalizeSkus($res['response']['model_list'] ?? []),
                ];
            }

            return ['success' => false, 'message' => 'Gagal mendorong produk: ' . json_encode($res)];
        } catch (\Exception $e) {
            Log::error('Shopee pushProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        try {
            $payload = $this->buildProductPayload($product, $shop);
            $payload['item_id'] = (int) $externalProductId;

            $res = $this->client->request('POST', '/api/v2/product/update_item', $payload, $shop->access_token, $shop->shop_id);

            return [
                'success' => true,
                'message' => 'Produk berhasil diperbarui di Shopee',
                'skus' => $this->normalizeSkus($res['response']['model_list'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::error('Shopee updateProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {
        try {
            $this->client->request('POST', '/api/v2/product/delete_item', [
                'item_id' => (int) $externalProductId,
            ], $shop->access_token, $shop->shop_id);

            return ['success' => true, 'message' => 'Produk berhasil dihapus dari Shopee'];
        } catch (\Exception $e) {
            Log::error('Shopee deleteProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function activateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->unlist($shop, $externalProductId, false);
    }

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->unlist($shop, $externalProductId, true);
    }

    protected function unlist(ChannelShop $shop, string $externalProductId, bool $unlist): array
    {
        try {
            $this->client->request('POST', '/api/v2/product/unlist_item', [
                'item_list' => [['item_id' => (int) $externalProductId, 'unlist' => $unlist]],
            ], $shop->access_token, $shop->shop_id);

            return ['success' => true, 'message' => $unlist ? 'Produk dinonaktifkan di Shopee' : 'Produk diaktifkan di Shopee'];
        } catch (\Exception $e) {
            Log::error('Shopee unlist error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $channelWarehouse = DB::table('channel_warehouses')->where('store_id', $shop->shop_id)->first();

        $priceList = [];
        $stockList = [];

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function ($q) use ($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if (! $mapping) {
                continue;
            }

            $modelId = (int) ($mapping->external_sku_id ?? 0);

            $availableQty = 0;
            if ($channelWarehouse) {
                $availableQty = (int) DB::table('inventories')
                    ->where('item_id', $variant->id)
                    ->where('location_id', $channelWarehouse->location_id)
                    ->sum('available');
            }

            $priceList[] = ['model_id' => $modelId, 'original_price' => (float) $variant->sell_price];
            $stockList[] = ['model_id' => $modelId, 'seller_stock' => [['stock' => max(0, $availableQty)]]];
        }

        if (empty($priceList)) {
            return ['success' => false, 'message' => 'Tidak ada SKU yang terhubung untuk diperbarui'];
        }

        try {
            $this->client->request('POST', '/api/v2/product/update_price', [
                'item_id' => (int) $externalProductId,
                'price_list' => $priceList,
            ], $shop->access_token, $shop->shop_id);

            $this->client->request('POST', '/api/v2/product/update_stock', [
                'item_id' => (int) $externalProductId,
                'stock_list' => $stockList,
            ], $shop->access_token, $shop->shop_id);

            return ['success' => true, 'message' => 'Harga dan stok berhasil disinkronisasi ke Shopee'];
        } catch (\Exception $e) {
            Log::error('Shopee syncPriceAndStock error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function mapInboundProduct(array $channelData, string $shopId): array
    {
        return $this->inboundMapper->map($channelData, $shopId);
    }

    protected function buildProductPayload(Product $product, ChannelShop $shop): array
    {
        $product->loadMissing('variants.options', 'media');

        // Stok dikirim dari sistem kita ke channel (bukan diimpor dari channel).
        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $images = $product->media->where('media_type', 'image');

        // Gambar level-produk: media tanpa variant_id (fallback ke semua bila tak ada yang khusus produk).
        $productImageUrls = $images->whereNull('variant_id')->sortBy('sort_order')->pluck('url')->values()->all();
        if (empty($productImageUrls)) {
            $productImageUrls = $images->sortBy('sort_order')->pluck('url')->values()->all();
        }
        $imageIds = $this->mediaUploader->uploadFromUrls($productImageUrls);

        // Gambar per varian → image_id untuk dipasang pada opsi tier_variation.
        $variantImageIdById = [];
        foreach ($images->whereNotNull('variant_id')->sortBy('sort_order')->groupBy('variant_id') as $variantId => $group) {
            $url = $group->first()->url ?? null;
            if (! $url) {
                continue;
            }
            $imageId = $this->mediaUploader->uploadOne($url);
            if ($imageId) {
                $variantImageIdById[$variantId] = $imageId;
            }
        }

        $internal = $product->toArray();
        $internal['variants'] = $product->variants->map(function ($variant) use ($variantImageIdById, $stockByVariant) {
            $arr = $variant->toArray();
            if (! empty($variantImageIdById[$variant->id])) {
                $arr['image_id'] = $variantImageIdById[$variant->id];
            }
            $arr['stock'] = $stockByVariant[$variant->id] ?? 0;

            return $arr;
        })->all();

        return $this->outboundMapper->map($internal, $imageIds, config('channel.shopee_defaults', []));
    }

    protected function normalizeSkus(array $modelList): array
    {
        return array_values(array_filter(array_map(function ($model) {
            if (empty($model['model_sku'])) {
                return null;
            }

            return [
                'seller_sku' => $model['model_sku'],
                'id' => (string) ($model['model_id'] ?? ''),
            ];
        }, $modelList)));
    }
}
