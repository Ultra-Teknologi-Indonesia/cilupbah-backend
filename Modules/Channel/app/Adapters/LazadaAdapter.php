<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Services\LazadaImageUploader;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Channel\Services\LazadaToInternalProductMapper;
use Modules\Product\Models\Product;

class LazadaAdapter implements MarketplaceAdapterInterface
{
    public function __construct(
        protected LazadaClient $client,
        protected LazadaProductMapper $outboundMapper,
        protected LazadaToInternalProductMapper $inboundMapper,
        protected ChannelStockResolver $stockResolver,
        protected LazadaImageUploader $imageUploader,
    ) {}

    public function getChannelCode(): string
    {
        return 'lazada';
    }

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array
    {
        $payload = $this->buildProductPayload($product, $shop);

        try {
            $res = $this->client->request('POST', '/product/create', [
                'payload' => json_encode($payload),
            ], $shop->access_token);

            $itemId = $res['data']['item_id'] ?? null;

            if ($itemId) {
                return [
                    'success' => true,
                    'external_product_id' => (string) $itemId,
                    'message' => 'Produk berhasil didorong ke Lazada',
                    'skus' => $this->normalizeSkus($res['data']['sku_list'] ?? []),
                ];
            }

            return ['success' => false, 'message' => 'Gagal mendorong produk: ' . json_encode($res)];
        } catch (\Exception $e) {
            Log::error('Lazada pushProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $payload = $this->buildProductPayload($product, $shop);
        $payload['Request']['Product']['ItemId'] = $externalProductId;

        try {
            $res = $this->client->request('POST', '/product/update', [
                'payload' => json_encode($payload),
            ], $shop->access_token);

            return [
                'success' => true,
                'message' => 'Produk berhasil diperbarui di Lazada',
                'skus' => $this->normalizeSkus($res['data']['sku_list'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::error('Lazada updateProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {

        $skus = $this->sellerSkusForExternalProduct($shop, $externalProductId);

        if (empty($skus)) {
            return ['success' => false, 'message' => 'Tidak ada SKU terpetakan untuk produk ini'];
        }

        try {
            $this->client->request('POST', '/product/remove', [
                'seller_sku_list' => json_encode($skus),
            ], $shop->access_token);

            return ['success' => true, 'message' => 'Produk berhasil dihapus dari Lazada'];
        } catch (\Exception $e) {
            Log::error('Lazada deleteProduct error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function activateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->setStatus($shop, $externalProductId, 'active', 'Produk berhasil diaktifkan di Lazada');
    }

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return $this->setStatus($shop, $externalProductId, 'inactive', 'Produk berhasil dinonaktifkan di Lazada');
    }

    protected function setStatus(ChannelShop $shop, string $externalProductId, string $status, string $successMessage): array
    {
        $payload = [
            'Request' => [
                'Product' => [
                    'ItemId' => $externalProductId,
                    'Status' => $status,
                ],
            ],
        ];

        try {
            $this->client->request('POST', '/product/update', [
                'payload' => json_encode($payload),
            ], $shop->access_token);

            return ['success' => true, 'message' => $successMessage];
        } catch (\Exception $e) {
            Log::error("Lazada setStatus({$status}) error: " . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $skuPayloads = [];

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function ($q) use ($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if (! $mapping || empty($variant->sku)) {
                continue;
            }

            $availableQty = $stockByVariant[$variant->id] ?? 0;

            $skuPayloads[] = [
                'SellerSku' => $variant->sku,
                'Price' => (string) $variant->sell_price,
                'Quantity' => (string) max(0, $availableQty),
            ];
        }

        if (empty($skuPayloads)) {
            return ['success' => false, 'message' => 'Tidak ada SKU yang terhubung untuk diperbarui'];
        }

        $payload = ['Request' => ['Product' => ['Skus' => ['Sku' => $skuPayloads]]]];

        try {
            $this->client->request('POST', '/product/price_quantity/update', [
                'payload' => json_encode($payload),
            ], $shop->access_token);

            return ['success' => true, 'message' => 'Harga dan stok berhasil disinkronisasi ke Lazada'];
        } catch (\Exception $e) {
            Log::error('Lazada syncPriceAndStock error: ' . $e->getMessage());

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

        $urlsToMigrate = array_values(array_unique(array_merge($imageUrls, array_values($variantImageById))));
        $migratedMap = $this->imageUploader->upload($urlsToMigrate, $shop->access_token)['map'];

        $imageUrls = array_values(array_filter(array_map(
            fn ($url) => $migratedMap[$url] ?? null,
            $imageUrls
        )));

        $variantImageById = array_filter(array_map(
            fn ($url) => $migratedMap[$url] ?? null,
            $variantImageById
        ));

        $internal = $product->toArray();
        $internal['variants'] = $product->variants->map(function ($variant) use ($variantImageById, $stockByVariant) {
            $arr = $variant->toArray();
            if (! empty($variantImageById[$variant->id])) {
                $arr['image_url'] = $variantImageById[$variant->id];
            }
            $arr['stock'] = $stockByVariant[$variant->id] ?? 0;

            return $arr;
        })->all();

        return $this->outboundMapper->map($internal, $imageUrls, config('channel.lazada_defaults', []));
    }

    protected function normalizeSkus(array $skuList): array
    {
        return array_values(array_filter(array_map(function ($sku) {
            if (empty($sku['seller_sku']) && empty($sku['SellerSku'])) {
                return null;
            }

            return [
                'seller_sku' => $sku['seller_sku'] ?? $sku['SellerSku'],
                'id' => (string) ($sku['sku_id'] ?? $sku['SkuId'] ?? ''),
            ];
        }, $skuList)));
    }

    protected function sellerSkusForExternalProduct(ChannelShop $shop, string $externalProductId): array
    {
        return DB::table('product_channel_mappings as pcm')
            ->join('product_variant_channel_mappings as pvcm', 'pvcm.product_channel_mapping_id', '=', 'pcm.id')
            ->join('product_variants as pv', 'pv.id', '=', 'pvcm.variant_id')
            ->where('pcm.channel_shop_id', $shop->id)
            ->where('pcm.external_product_id', $externalProductId)
            ->pluck('pv.sku')
            ->filter()
            ->values()
            ->all();
    }
}
