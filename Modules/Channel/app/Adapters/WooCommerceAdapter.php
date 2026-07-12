<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\WooCommerceClient;
use Modules\Channel\Services\WooCommerceProductMapper;
use Modules\Channel\Services\WooCommerceToInternalProductMapper;
use Modules\Product\Models\Product;

class WooCommerceAdapter implements MarketplaceAdapterInterface
{
    public function __construct(
        protected WooCommerceClient $client,
        protected WooCommerceProductMapper $outboundMapper,
        protected WooCommerceToInternalProductMapper $inboundMapper,
        protected ChannelStockResolver $stockResolver,
    ) {}

    public function getChannelCode(): string
    {
        return 'woocommerce';
    }

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array
    {
        $payload = $this->buildProductPayload($product, $shop);
        $variations = $payload['_variations'] ?? [];
        unset($payload['_variations']);

        try {
            $res = $this->client->post($shop, 'products', $payload);
            $externalId = $res['id'] ?? null;

            if (! $externalId) {
                return ['success' => false, 'message' => 'Gagal mendorong produk: ' . json_encode($res)];
            }

            return [
                'success' => true,
                'external_product_id' => (string) $externalId,
                'message' => 'Produk berhasil didorong ke WooCommerce',
                'skus' => $this->syncVariations($shop, (string) $externalId, $variations, $res),
            ];
        } catch (\Exception $e) {
            Log::error('WooCommerce pushProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $payload = $this->buildProductPayload($product, $shop);
        $variations = $payload['_variations'] ?? [];
        unset($payload['_variations']);

        try {
            $res = $this->client->put($shop, "products/{$externalProductId}", $payload);

            return [
                'success' => true,
                'message' => 'Produk berhasil diperbarui di WooCommerce',
                'skus' => $this->syncVariations($shop, $externalProductId, $variations, $res),
            ];
        } catch (\Exception $e) {
            Log::error('WooCommerce updateProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {
        try {
            $this->client->delete($shop, "products/{$externalProductId}", ['force' => true]);

            return ['success' => true, 'message' => 'Produk berhasil dihapus dari WooCommerce'];
        } catch (\Exception $e) {
            Log::error('WooCommerce deleteProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function activateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->setStatus($shop, $externalProductId, 'publish', 'Produk berhasil diaktifkan di WooCommerce');
    }

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->setStatus($shop, $externalProductId, 'draft', 'Produk berhasil dinonaktifkan di WooCommerce');
    }

    protected function setStatus(ChannelShop $shop, string $externalProductId, string $status, string $successMessage): array
    {
        try {
            $this->client->put($shop, "products/{$externalProductId}", ['status' => $status]);

            return ['success' => true, 'message' => $successMessage];
        } catch (\Exception $e) {
            Log::error("WooCommerce setStatus({$status}) error: " . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $variationUpdates = [];
        $simplePayload = null;

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function ($q) use ($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if (! $mapping || ! $mapping->sync_enabled || empty($variant->sku)) {
                continue;
            }

            $availableQty = max(0, (int) ($stockByVariant[$variant->id] ?? 0));

            if (! empty($mapping->external_sku_id)) {
                $variationUpdates[] = [
                    'id' => (int) $mapping->external_sku_id,
                    'regular_price' => (string) $variant->sell_price,
                    'manage_stock' => true,
                    'stock_quantity' => $availableQty,
                ];
            } else {
                $simplePayload = [
                    'regular_price' => (string) $variant->sell_price,
                    'manage_stock' => true,
                    'stock_quantity' => $availableQty,
                ];
            }
        }

        if (empty($variationUpdates) && $simplePayload === null) {
            return ['success' => false, 'message' => 'Tidak ada SKU yang terhubung untuk diperbarui'];
        }

        try {
            if (! empty($variationUpdates)) {
                $this->client->post($shop, "products/{$externalProductId}/variations/batch", [
                    'update' => $variationUpdates,
                ]);
            }

            if ($simplePayload !== null) {
                $this->client->put($shop, "products/{$externalProductId}", $simplePayload);
            }

            return ['success' => true, 'message' => 'Harga dan stok berhasil disinkronisasi ke WooCommerce'];
        } catch (\Exception $e) {
            Log::error('WooCommerce syncPriceAndStock error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function mapInboundProduct(array $channelData, string $shopId): array
    {
        return $this->inboundMapper->map($channelData, $shopId);
    }

    protected function syncVariations(ChannelShop $shop, string $externalProductId, array $variations, array $productResponse): array
    {
        if (empty($variations)) {
            if (! empty($productResponse['sku'])) {
                return [['seller_sku' => $productResponse['sku'], 'id' => (string) $externalProductId]];
            }

            return [];
        }

        $created = $this->client->post($shop, "products/{$externalProductId}/variations/batch", [
            'create' => array_values($variations),
        ]);

        return $this->normalizeSkus($created['create'] ?? []);
    }

    protected function normalizeSkus(array $variationList): array
    {
        return array_values(array_filter(array_map(function ($variation) {
            if (empty($variation['sku'])) {
                return null;
            }

            return [
                'seller_sku' => $variation['sku'],
                'id' => (string) ($variation['id'] ?? ''),
            ];
        }, $variationList)));
    }

    protected function buildProductPayload(Product $product, ChannelShop $shop): array
    {
        $product->loadMissing('variants.options', 'media');

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $images = $product->media->where('media_type', 'image');

        $imageUrls = $images->whereNull('variant_id')->sortBy('sort_order')->pluck('url')->values()->all();
        if (empty($imageUrls)) {
            $imageUrls = $images->sortBy('sort_order')->pluck('url')->values()->all();
        }

        $variantImageById = [];
        foreach ($images->whereNotNull('variant_id')->sortBy('sort_order')->groupBy('variant_id') as $variantId => $group) {
            $url = $group->first()->url ?? null;
            if ($url) {
                $variantImageById[$variantId] = $url;
            }
        }

        $internal = $product->toArray();
        $internal['variants'] = $product->variants->map(function ($variant) use ($variantImageById, $stockByVariant) {
            $arr = $variant->toArray();
            if (! empty($variantImageById[$variant->id])) {
                $arr['image_url'] = $variantImageById[$variant->id];
            }
            $arr['stock'] = $stockByVariant[$variant->id] ?? 0;

            return $arr;
        })->all();

        return $this->outboundMapper->map($internal, $imageUrls, config('channel.woocommerce_defaults', []));
    }
}
