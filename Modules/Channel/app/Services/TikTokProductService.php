<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelProductRepository;

class TikTokProductService
{
    protected TikTokClient $client;
    protected TikTokProductMapper $mapper;
    protected ChannelShopRepository $shopRepository;
    protected ChannelProductRepository $productRepository;

    public function __construct(
        TikTokClient $client, 
        TikTokProductMapper $mapper,
        ChannelShopRepository $shopRepository,
        ChannelProductRepository $productRepository
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->shopRepository = $shopRepository;
        $this->productRepository = $productRepository;
    }

    public function pushProduct(int $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $product = $this->productRepository->findById($productId);
        if (!$product) {
            throw new \Exception("Product not found");
        }

        $variants = $this->productRepository->getVariantsByProductId($productId);
        $media = $this->productRepository->getMediaByProductId($productId);

        $uploadedImageIds = [];
        foreach ($media as $m) {
            if ($m->media_type === 'image') {
                $base64 = null;
                try {
                    $content = @file_get_contents($m->url);
                    if ($content) {
                        $base64 = base64_encode($content);
                    }
                } catch (\Exception $e) {}

                if (!$base64) {
                    $base64 = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgBLAEsAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAP/9k=';
                }

                try {
                    $fileContent = base64_decode($base64);
                    
                    $res = $this->client->request(
                        'POST', 
                        '/product/202309/images/upload', 
                        [], 
                        [], 
                        $accessToken,
                        [
                            'data' => [
                                'contents' => $fileContent,
                                'filename' => 'product_image_' . rand(100, 999) . '.jpg'
                            ]
                        ]
                    );
                    
                    if (isset($res['data']['uri'])) {
                        $uploadedImageIds[] = $res['data']['uri'];
                    } else {
                        Log::warning("TikTok Image Upload unexpected response: " . json_encode($res));
                    }
                } catch (\Exception $e) {
                    Log::error("TikTok Image Upload failed: " . $e->getMessage());
                    throw new \Exception("Failed to upload image to TikTok: " . $e->getMessage());
                }
            }
        }

        $internalProduct = (array)$product;
        $internalProduct['variants'] = $variants->map(function ($v) {
            $variantArr = (array)$v;
            $options = $this->productRepository->getVariantOptions($v->id);
            $variantArr['options'] = array_map(fn($opt) => (array)$opt, $options);
            return $variantArr;
        })->toArray();

        $payload = $this->mapper->map($internalProduct, $uploadedImageIds);

        $res = $this->client->request('POST', '/product/202309/products', ['shop_cipher' => $shopCipher], $payload, $accessToken);
        
        if (isset($res['data']['product_id'])) {
            $this->productRepository->updateChannelProductId($productId, $res['data']['product_id']);
        }

        return $res;
    }

    public function pullProducts(string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $queries = [
            'shop_cipher' => $shopCipher,
            'page_size' => 100,
        ];
        
        $res = $this->client->request('POST', '/product/202309/products/search', $queries, [], $accessToken);
        
        if (!isset($res['data']['products'])) {
            return 0; 
        }

        $productService = app(\Modules\Product\Services\ProductService::class);
        $mapper = app(TikTokToInternalProductMapper::class);

        $count = 0;
        foreach ($res['data']['products'] as $item) {
            try {
                $internalData = $mapper->map($item, $shopId);
                $insertedId = $productService->upsertFromChannel($internalData);
                
                if ($insertedId) {
                    $count++;
                }
            } catch (\Exception $e) {
                Log::error("Failed to pull product {$item['id']}: " . $e->getMessage());
            }
        }
        
        return $count;
    }

    public function syncPriceAndInventory(int $productId, string $shopId)
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = $this->productRepository->findById($productId);
        if (!$product || !$product->channel_product_id) {
            throw new \Exception("Product not found or not synced to TikTok yet.");
        }

        $variants = $this->productRepository->getVariantsByProductId($productId);

        $channelWarehouse = DB::table('channel_warehouses')
            ->where('store_id', $shopId)
            ->first();

        $skus = [];
        $inventorySkus = [];

        foreach ($variants as $v) {
            $skus[] = [
                'seller_sku' => $v->sku,
                'price' => [
                    'amount' => (string)($v->sell_price ?? 0),
                    'currency' => 'IDR'
                ]
            ];

            $availableQty = 0;
            if ($channelWarehouse) {
                $availableQty = (int) DB::table('inventories')
                    ->where('item_id', $productId)
                    ->where('location_id', $channelWarehouse->location_id)
                    ->sum('available');
            }

            $inventorySkus[] = [
                'seller_sku' => $v->sku,
                'inventory' => [
                    [
                        'quantity' => max(0, $availableQty),
                        'warehouse_id' => $channelWarehouse->channel_location_id ?? '',
                    ]
                ]
            ];
        }

        $pricePayload = ['product_id' => $product->channel_product_id, 'skus' => $skus];
        $invPayload = ['product_id' => $product->channel_product_id, 'skus' => $inventorySkus];

        try {
            $this->client->request('POST', "/product/202309/products/{$product->channel_product_id}/prices/update", ['shop_cipher' => $shop->shop_cipher ?? ''], $pricePayload, $shop->access_token);
        } catch (\Exception $e) {
            Log::warning("TikTok price update failed: " . $e->getMessage());
        }

        try {
            $this->client->request('POST', "/product/202309/products/{$product->channel_product_id}/inventory/update", ['shop_cipher' => $shop->shop_cipher ?? ''], $invPayload, $shop->access_token);
        } catch (\Exception $e) {
            Log::warning("TikTok inventory update failed: " . $e->getMessage());
        }

        return true;
    }

    public function syncInventoryBySku(string $sku, string $shopId): bool
    {
        $variant = $this->productRepository->getVariantBySku($sku);
        if (!$variant) {
            return false;
        }

        try {
            $this->syncPriceAndInventory($variant->product_id, $shopId);
            return true;
        } catch (\Exception $e) {
            Log::warning("syncInventoryBySku failed for {$sku} on shop {$shopId}: " . $e->getMessage());
            return false;
        }
    }

    public function pushUpdate(int $productId, string $shopId)
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product || !$product->channel_product_id) {
            throw new \Exception("Product not found or not synced to TikTok yet");
        }

        $variants = DB::table('product_variants')->where('product_id', $productId)->get();
        $media = DB::table('product_media')->where('product_id', $productId)->get();

        $uploadedImageIds = [];
        foreach ($media as $m) {
            $uploadedImageIds[] = $m->url; 
        }

        $internalProduct = (array)$product;
        $internalProduct['variants'] = $variants->map(function ($v) {
            $variantArr = (array)$v;
            $options = DB::table('variant_options')->where('variant_id', $v->id)->get()->toArray();
            $variantArr['options'] = array_map(fn($opt) => (array)$opt, $options);
            return $variantArr;
        })->toArray();

        $payload = $this->mapper->map($internalProduct, $uploadedImageIds);

        return $this->client->request('PUT', "/product/202309/products/{$product->channel_product_id}", ['shop_cipher' => $shop->shop_cipher ?? ''], $payload, $shop->access_token);
    }

    public function deleteProduct(int $productId, string $shopId)
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product || !$product->channel_product_id) {
            return true;
        }

        return $this->client->request('DELETE', "/product/202309/products", ['shop_cipher' => $shop->shop_cipher ?? ''], ['product_ids' => [$product->channel_product_id]], $shop->access_token);
    }

    public function activateProduct(int $productId, string $shopId)
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product || !$product->channel_product_id) {
            throw new \Exception("Product not synced to TikTok yet");
        }

        return $this->client->request('POST', "/product/202309/products/activate", ['shop_cipher' => $shop->shop_cipher ?? ''], ['product_ids' => [$product->channel_product_id]], $shop->access_token);
    }

    public function deactivateProduct(int $productId, string $shopId)
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product || !$product->channel_product_id) {
            throw new \Exception("Product not synced to TikTok yet");
        }

        return $this->client->request('POST', "/product/202309/products/deactivate", ['shop_cipher' => $shop->shop_cipher ?? ''], ['product_ids' => [$product->channel_product_id]], $shop->access_token);
    }

    public function bulkPushProducts(string $shopId): int
    {
        $failCount = 0;
        $products = $this->productRepository->getActiveProducts();

        foreach ($products as $product) {
            try {
                if (empty($product->channel_product_id)) {
                    $this->pushProduct($product->id, $shopId);
                } else {
                    $this->pushUpdate($product->id, $shopId);
                }
            } catch (\Exception $e) {
                Log::error("Failed to bulk push product {$product->id} to TikTok: " . $e->getMessage());
                $failCount++;
            }
        }

        return $failCount;
    }
}
