<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokProductMapper;
use Modules\Channel\Services\TikTokToInternalProductMapper;
use Modules\Product\Models\Product;

class TikTokAdapter implements MarketplaceAdapterInterface
{
    protected TikTokClient $client;
    protected TikTokProductMapper $outboundMapper;
    protected TikTokToInternalProductMapper $inboundMapper;

    public function __construct(
        TikTokClient $client,
        TikTokProductMapper $outboundMapper,
        TikTokToInternalProductMapper $inboundMapper
    ) {
        $this->client = $client;
        $this->outboundMapper = $outboundMapper;
        $this->inboundMapper = $inboundMapper;
    }

    public function getChannelCode(): string
    {
        return 'tiktok';
    }

    public function pushProduct(Product $product, ChannelShop $shop): array
    {
        $imageUris = [];
        foreach ($product->variants as $variant) {
        }

        $internalProductArray = $product->toArray();
        $internalProductArray['variants'] = $product->variants->toArray();
        
        $config = config('channel.tiktok_defaults', []);
        
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
        $internalProductArray = $product->toArray();
        $internalProductArray['variants'] = $product->variants->toArray();
        
        $config = config('channel.tiktok_defaults', []);
        $payload = $this->outboundMapper->map($internalProductArray, [], $config);
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
        $inventorySkus = [];
        $priceSkus = [];
        
        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function($q) use($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if ($mapping && $mapping->external_sku_id) {
                $inventorySkus[] = [
                    'id' => $mapping->external_sku_id,
                    'inventory' => [
                        [
                            'warehouse_id' => config('channel.tiktok_defaults.warehouse_id', '7646426075561690887'),
                            'quantity' => (int) ($variant->stock ?? 0)
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
