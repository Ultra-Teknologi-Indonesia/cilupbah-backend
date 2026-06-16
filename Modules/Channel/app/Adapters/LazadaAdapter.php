<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Services\LazadaProductMapper;
use Modules\Channel\Services\LazadaToInternalProductMapper;
use Modules\Product\Models\Product;

class LazadaAdapter implements MarketplaceAdapterInterface
{
    public function __construct(
        protected LazadaClient $client,
        protected LazadaProductMapper $outboundMapper,
        protected LazadaToInternalProductMapper $inboundMapper,
    ) {}

    public function getChannelCode(): string
    {
        return 'lazada';
    }

    public function pushProduct(Product $product, ChannelShop $shop): array
    {
        $payload = $this->buildProductPayload($product);

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
        $payload = $this->buildProductPayload($product);
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

        return ['success' => false, 'message' => 'Aktivasi produk tidak didukung API Lazada'];
    }

    public function deactivateProduct(ChannelShop $shop, string $externalProductId): array
    {
        return ['success' => false, 'message' => 'Deaktivasi produk tidak didukung API Lazada'];
    }

    public function syncPriceAndStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $channelWarehouse = DB::table('channel_warehouses')
            ->where('store_id', $shop->shop_id)
            ->first();

        $skuPayloads = [];

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function ($q) use ($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if (! $mapping || empty($variant->sku)) {
                continue;
            }

            $availableQty = 0;
            if ($channelWarehouse) {
                $availableQty = (int) DB::table('inventories')
                    ->where('item_id', $variant->id)
                    ->where('location_id', $channelWarehouse->location_id)
                    ->sum('available');
            }

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

    protected function buildProductPayload(Product $product): array
    {
        // Pastikan opsi varian termuat agar sale-property terpetakan ke channel.
        $product->loadMissing('variants.options');

        $internal = $product->toArray();
        $internal['variants'] = $product->variants->toArray();

        $imageUrls = [];
        foreach ($product->media ?? [] as $media) {
            if (! empty($media->url)) {
                $imageUrls[] = $media->url;
            }
        }

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
