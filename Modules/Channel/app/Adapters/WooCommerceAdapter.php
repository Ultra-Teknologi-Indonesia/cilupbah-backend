<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\WooCommerceClient;
use Modules\Channel\Services\WooCommerceProductMapper;
use Modules\Channel\Services\WooCommerceToInternalProductMapper;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariantChannelMapping;

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
                return ['success' => false, 'message' => 'Gagal mendorong produk: '.json_encode($res)];
            }
        } catch (\Exception $e) {
            Log::error('WooCommerce pushProduct error: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }

        try {
            $skus = $this->syncVariations($shop, (string) $externalId, $variations, $res);
        } catch (\Exception $e) {
            Log::error('WooCommerce pushProduct variations error: '.$e->getMessage());

            return [
                'success' => false,
                'external_product_id' => (string) $externalId,
                'message' => 'Produk berhasil dibuat namun sinkronisasi variasi gagal: '.$e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'external_product_id' => (string) $externalId,
            'message' => 'Produk berhasil didorong ke WooCommerce',
            'skus' => $skus,
        ];
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
            Log::error('WooCommerce updateProduct error: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {
        try {
            $this->client->delete($shop, "products/{$externalProductId}", ['force' => true]);

            return ['success' => true, 'message' => 'Produk berhasil dihapus dari WooCommerce'];
        } catch (\Exception $e) {
            Log::error('WooCommerce deleteProduct error: '.$e->getMessage());

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
            Log::error("WooCommerce setStatus({$status}) error: ".$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncPriceAndStock(
        Product $product,
        ChannelShop $shop,
        string $externalProductId,
        ?ProductChannelMapping $listing = null,
    ): array {
        $listing = ChannelVariantMappingResolver::listing($product, $shop, $externalProductId, $listing);
        if (! $listing) {
            return ['success' => false, 'message' => 'Tidak ada SKU terhubung: listing WooCommerce tidak ditemukan atau tidak sesuai dengan produk.'];
        }

        $mappings = ChannelVariantMappingResolver::enabledForListing($listing);
        $resolved = $this->resolveStaleVariationTarget($product, $shop, $listing, $mappings, $externalProductId);

        if (isset($resolved['error'])) {
            return ['success' => false, 'message' => $resolved['error']];
        }

        if ($resolved !== null) {
            $listing = $resolved['listing'];
            $mappings = $resolved['mappings'];
            $externalProductId = $resolved['external_product_id'];
        }

        $payloadError = ChannelVariantMappingResolver::stockPayloadError(
            $mappings,
            'external_sku_id',
            'Variation ID WooCommerce',
            true,
        );
        if ($payloadError !== null) {
            return ['success' => false, 'message' => $payloadError];
        }

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $mappings->pluck('variant'));

        $variationUpdates = [];
        $simplePayload = null;

        $isVariable = $mappings->count() > 1;

        foreach ($mappings as $mapping) {
            $variant = $mapping->variant;

            $availableQty = max(0, (int) ($stockByVariant[$variant->id] ?? 0));

            if (! empty($mapping->external_sku_id)) {
                $variationUpdates[] = [
                    'id' => (int) $mapping->external_sku_id,
                    'regular_price' => (string) $variant->sell_price,
                    'manage_stock' => true,
                    'stock_quantity' => $availableQty,
                ];
            } elseif (! $isVariable) {
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
                foreach ($variationUpdates as $update) {
                    $this->client->put($shop, "products/{$externalProductId}/variations/{$update['id']}", $update);
                }
            }

            if ($simplePayload !== null) {
                $this->client->put($shop, "products/{$externalProductId}", $simplePayload);
            }

            return ['success' => true, 'message' => 'Harga dan stok berhasil disinkronisasi ke WooCommerce'];
        } catch (\Exception $e) {
            Log::error('WooCommerce syncPriceAndStock error: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncStock(
        Product $product,
        ChannelShop $shop,
        string $externalProductId,
        ?ProductChannelMapping $listing = null,
    ): array {
        $listing = ChannelVariantMappingResolver::listing($product, $shop, $externalProductId, $listing);
        if (! $listing) {
            return ['success' => false, 'message' => 'Tidak ada SKU terhubung: listing WooCommerce tidak ditemukan atau tidak sesuai dengan produk.'];
        }

        $mappings = ChannelVariantMappingResolver::enabledForListing($listing);
        $resolved = $this->resolveStaleVariationTarget($product, $shop, $listing, $mappings, $externalProductId);

        if (isset($resolved['error'])) {
            return ['success' => false, 'message' => $resolved['error']];
        }

        if ($resolved !== null) {
            $listing = $resolved['listing'];
            $mappings = $resolved['mappings'];
            $externalProductId = $resolved['external_product_id'];
        }

        $payloadError = ChannelVariantMappingResolver::stockPayloadError(
            $mappings,
            'external_sku_id',
            'Variation ID WooCommerce',
            true,
        );
        if ($payloadError !== null) {
            return ['success' => false, 'message' => $payloadError];
        }

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $mappings->pluck('variant'));

        $variationUpdates = [];
        $simplePayload = null;

        $isVariable = $mappings->count() > 1;

        foreach ($mappings as $mapping) {
            $variant = $mapping->variant;

            $availableQty = max(0, (int) ($stockByVariant[$variant->id] ?? 0));

            if (! empty($mapping->external_sku_id)) {
                $variationUpdates[] = [
                    'id' => (int) $mapping->external_sku_id,
                    'manage_stock' => true,
                    'stock_quantity' => $availableQty,
                ];
            } elseif (! $isVariable) {
                $simplePayload = [
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
                foreach ($variationUpdates as $update) {
                    $this->client->put($shop, "products/{$externalProductId}/variations/{$update['id']}", $update);
                }
            }

            if ($simplePayload !== null) {
                $this->client->put($shop, "products/{$externalProductId}", $simplePayload);
            }

            return ['success' => true, 'message' => 'Stok berhasil disinkronisasi ke WooCommerce'];
        } catch (\Exception $e) {
            Log::error('WooCommerce syncStock error: '.$e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Resolve a stale WooCommerce simple-looking mapping that actually points
     * at a variation ID already mapped under its canonical parent listing.
     *
     * WooCommerce variation IDs are not valid product IDs for the simple
     * product endpoint. Only resolve when the database contains exactly one
     * unambiguous canonical mapping for the same master variant and seller SKU.
     */
    private function resolveStaleVariationTarget(
        Product $product,
        ChannelShop $shop,
        ProductChannelMapping $listing,
        Collection $mappings,
        string $externalProductId,
    ): ?array {
        if ($mappings->count() !== 1) {
            return null;
        }

        $mapping = $mappings->first();

        if (filled($mapping->external_sku_id) || blank($mapping->channel_seller_sku)) {
            return null;
        }

        $candidates = ProductVariantChannelMapping::query()
            ->where('variant_id', $mapping->variant_id)
            ->where('channel_seller_sku', $mapping->channel_seller_sku)
            ->where('external_sku_id', (string) $externalProductId)
            ->where('sync_enabled', true)
            ->whereHas('variant', function ($query) use ($product): void {
                $query->where('product_id', $product->id)
                    ->where('is_active', true);
            })
            ->whereHas('channelMapping', function ($query) use ($product, $shop, $listing): void {
                $query->where('product_id', $product->id)
                    ->where('channel_shop_id', $shop->id)
                    ->where('id', '!=', $listing->id)
                    ->whereNotNull('external_product_id')
                    ->where('external_product_id', '!=', '')
                    ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED);
            })
            ->with('channelMapping')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() > 1) {
            return [
                'error' => 'Mapping WooCommerce ambigu: satu variation ditemukan pada lebih dari satu listing parent.',
            ];
        }

        $canonicalMapping = $candidates->first();
        $canonicalListing = $canonicalMapping->channelMapping;

        Log::warning('WooCommerce stale variation mapping dialihkan ke listing parent canonical.', [
            'stale_listing_id' => $listing->id,
            'stale_external_product_id' => $externalProductId,
            'canonical_listing_id' => $canonicalListing->id,
            'canonical_external_product_id' => $canonicalListing->external_product_id,
            'external_sku_id' => $canonicalMapping->external_sku_id,
            'variant_id' => $mapping->variant_id,
        ]);

        return [
            'listing' => $canonicalListing,
            'mappings' => collect([$canonicalMapping]),
            'external_product_id' => (string) $canonicalListing->external_product_id,
        ];
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
