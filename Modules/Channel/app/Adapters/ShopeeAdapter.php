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

            $tierVariation = $payload['_tier_variation'] ?? null;
            $modelList = $payload['_model_list'] ?? null;
            unset($payload['_tier_variation'], $payload['_model_list']);

            $res = $this->client->request('POST', '/api/v2/product/add_item', $payload, $shop->access_token, $shop->shop_id);

            $itemId = $res['response']['item_id'] ?? null;

            if ($itemId) {
                $skus = [];

                if ($tierVariation && $modelList) {
                    $initRes = $this->client->request('POST', '/api/v2/product/init_tier_variation', [
                        'item_id' => $itemId,
                        'tier_variation' => $tierVariation,
                        'model' => $modelList,
                    ], $shop->access_token, $shop->shop_id);

                    $skus = $this->normalizeSkus($initRes['response']['model_list'] ?? $initRes['response']['model'] ?? []);
                }

                return [
                    'success' => true,
                    'external_product_id' => (string) $itemId,
                    'message' => 'Produk berhasil didorong ke Shopee',
                    'skus' => $skus,
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
            unset($payload['_model_list'], $payload['_tier_variation']);
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

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $images = $product->media->where('media_type', 'image');

        $productImageUrls = $images->whereNull('variant_id')->sortBy('sort_order')->pluck('url')->values()->all();
        if (empty($productImageUrls)) {
            $productImageUrls = $images->sortBy('sort_order')->pluck('url')->values()->all();
        }
        $imageIds = $this->mediaUploader->uploadFromUrls($productImageUrls);

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

        $config = config('channel.shopee_defaults', []);

        if (empty($config['logistic_info'])) {
            $config['logistic_info'] = $this->resolveLogistics($shop, $product);
        }

        $channelCategoryUuid = $this->resolveChannelCategoryUuid($product);
        if ($channelCategoryUuid) {
            $config['attribute_list'] = $this->buildAttributeList($product, $channelCategoryUuid);
            $config['tier_variation_name'] = $this->resolveTierVariationName($product, $channelCategoryUuid);
        }

        return $this->outboundMapper->map($internal, $imageIds, $config);
    }

    protected function resolveLogistics(ChannelShop $shop, Product $product): array
    {
        $res = $this->client->request('GET', '/api/v2/logistics/get_channel_list', [], $shop->access_token, $shop->shop_id);

        $weightKg = \Modules\Channel\Support\WeightConverter::toKg(
            $product->weight ?? 0.1,
            $product->weight_unit ?? 'kg'
        ) ?: 0.1;

        $length = (int) ($product->length ?? 10);
        $width = (int) ($product->width ?? 10);
        $height = (int) ($product->height ?? 10);

        $instantEligible = $weightKg <= 20.0 && $length <= 50 && $width <= 50 && $height <= 50;
        $instantKeywords = ['instant', 'same day', '2 jam', 'sameday'];

        return collect($res['response']['logistics_channel_list'] ?? [])
            ->filter(fn ($l) => $l['enabled'] ?? false)
            ->filter(function ($l) use ($instantEligible, $instantKeywords) {
                $name = strtolower($l['logistics_channel_name'] ?? '');
                foreach ($instantKeywords as $keyword) {
                    if (str_contains($name, $keyword)) {
                        return $instantEligible;
                    }
                }

                return true;
            })
            ->map(fn ($l) => [
                'logistic_id' => (int) $l['logistics_channel_id'],
                'enabled' => true,
            ])
            ->values()
            ->all();
    }

    protected function resolveChannelCategoryUuid(Product $product): ?string
    {
        if (! $product->category_id) {
            return null;
        }

        $channelId = DB::table('channels')->where('code', 'shopee')->value('id');
        if (! $channelId) {
            return null;
        }

        return DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $product->category_id)
            ->where('channel_categories.channel_id', $channelId)
            ->value('channel_categories.id');
    }

    protected function buildAttributeList(Product $product, string $channelCategoryUuid): array
    {
        $specs = DB::table('product_specifications')->where('product_id', $product->id)->get();
        $attributes = [];

        foreach ($specs as $spec) {
            $mapping = DB::table('attribute_channel_mappings')
                ->where('attribute_id', $spec->attribute_id)
                ->first();

            if (! $mapping) {
                continue;
            }

            $channelAttr = DB::table('channel_attributes')
                ->where('id', $mapping->channel_attribute_id)
                ->where('channel_category_id', $channelCategoryUuid)
                ->where('is_sale_prop', false)
                ->first();

            if (! $channelAttr) {
                continue;
            }

            $attrData = [
                'attribute_id' => (int) $channelAttr->external_id,
                'attribute_value_list' => [],
            ];

            if ($spec->attribute_option_id) {
                $optMapping = DB::table('attribute_option_channel_mappings')
                    ->where('attribute_option_id', $spec->attribute_option_id)
                    ->first();

                if ($optMapping) {
                    $channelOpt = DB::table('channel_attribute_options')
                        ->where('id', $optMapping->channel_attribute_option_id)
                        ->first();

                    if ($channelOpt) {
                        $attrData['attribute_value_list'][] = [
                            'value_id' => (int) $channelOpt->external_id,
                            'original_value_name' => $channelOpt->name,
                        ];
                    }
                }
            } elseif ($spec->text_value) {
                $attrData['attribute_value_list'][] = [
                    'original_value_name' => $spec->text_value,
                ];
            }

            if (! empty($attrData['attribute_value_list'])) {
                $attributes[] = $attrData;
            }
        }

        return $attributes;
    }

    protected function resolveTierVariationName(Product $product, string $channelCategoryUuid): ?string
    {
        $firstSalesOption = DB::table('product_variants as pv')
            ->join('variant_options as pvo', 'pvo.variant_id', '=', 'pv.id')
            ->where('pv.product_id', $product->id)
            ->whereNotNull('pvo.attribute_id')
            ->value('pvo.attribute_id');

        if (! $firstSalesOption) {
            return null;
        }

        $channelAttr = DB::table('attribute_channel_mappings as acm')
            ->join('channel_attributes as ca', 'ca.id', '=', 'acm.channel_attribute_id')
            ->where('acm.attribute_id', $firstSalesOption)
            ->where('ca.channel_category_id', $channelCategoryUuid)
            ->where('ca.is_sale_prop', true)
            ->value('ca.name');

        return $channelAttr;
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
