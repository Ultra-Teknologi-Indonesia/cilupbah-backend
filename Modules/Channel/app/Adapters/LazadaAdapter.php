<?php

namespace Modules\Channel\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\MarketplaceAdapterInterface;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelStockResolver;
use Modules\Channel\Services\LazadaAuthService;
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
        protected LazadaAuthService $authService,
    ) {}

    public function getChannelCode(): string
    {
        return 'lazada';
    }

    public function pushProduct(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array
    {
        $payload = $this->buildProductPayload($product, $shop, $attributeMapping);

        try {
            $res = $this->sendProductPayload('/product/create', $payload, $shop);

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

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => \Modules\Channel\Support\UploadErrorPresenter::fromThrowable('lazada', $e),
            ];
        }
    }

    public function updateProduct(Product $product, ChannelShop $shop, string $externalProductId): array
    {
        $payload = $this->buildProductPayload($product, $shop);

        try {
            $res = $this->sendProductPayload('/product/update', $payload, $shop);

            return [
                'success' => true,
                'message' => 'Produk berhasil diperbarui di Lazada',
                'skus' => $this->normalizeSkus($res['data']['sku_list'] ?? []),
            ];
        } catch (\Exception $e) {
            Log::error('Lazada updateProduct error: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => \Modules\Channel\Support\UploadErrorPresenter::fromThrowable('lazada', $e),
            ];
        }
    }

    public function deleteProduct(ChannelShop $shop, string $externalProductId): array
    {

        $skus = $this->sellerSkusForExternalProduct($shop, $externalProductId);

        if (empty($skus)) {
            return ['success' => false, 'message' => 'Tidak ada SKU terpetakan untuk produk ini'];
        }

        try {
            $this->withTokenRefresh($shop, fn ($token) => $this->client->request('POST', '/product/remove', [
                'seller_sku_list' => json_encode($skus),
            ], $token));

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
            $this->withTokenRefresh($shop, fn ($token) => $this->client->request('POST', '/product/update', [
                'payload' => json_encode($payload),
            ], $token));

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

            if (! $mapping || ! $mapping->sync_enabled || empty($variant->sku)) {
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
            $this->withTokenRefresh($shop, fn ($token) => $this->client->request('POST', '/product/price_quantity/update', [
                'payload' => json_encode($payload),
            ], $token));

            return ['success' => true, 'message' => 'Harga dan stok berhasil disinkronisasi ke Lazada'];
        } catch (\Exception $e) {
            Log::error('Lazada syncPriceAndStock error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function syncStock(Product $product, ChannelShop $shop, string $externalProductId): array
    {

        $stockByVariant = $this->stockResolver->availableByVariant($shop, $product->variants);

        $skuPayloads = [];

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings()->whereHas('channelMapping', function ($q) use ($shop) {
                $q->where('channel_shop_id', $shop->id);
            })->first();

            if (! $mapping || ! $mapping->sync_enabled || empty($variant->sku)) {
                continue;
            }

            $availableQty = $stockByVariant[$variant->id] ?? 0;

            $skuPayloads[] = [
                'SellerSku' => $variant->sku,
                'Quantity' => (string) max(0, $availableQty),
            ];
        }

        if (empty($skuPayloads)) {
            return ['success' => false, 'message' => 'Tidak ada SKU yang terhubung untuk diperbarui'];
        }

        $payload = ['Request' => ['Product' => ['Skus' => ['Sku' => $skuPayloads]]]];

        try {
            $this->withTokenRefresh($shop, fn ($token) => $this->client->request('POST', '/product/price_quantity/update', [
                'payload' => json_encode($payload),
            ], $token));

            return ['success' => true, 'message' => 'Stok berhasil disinkronisasi ke Lazada'];
        } catch (\Exception $e) {
            Log::error('Lazada syncStock error: ' . $e->getMessage());

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function mapInboundProduct(array $channelData, string $shopId): array
    {
        return $this->inboundMapper->map($channelData, $shopId);
    }

    protected function sendProductPayload(string $path, array $payload, ChannelShop $shop): array
    {
        $send = fn (array $body) => $this->withTokenRefresh(
            $shop,
            fn ($token) => $this->client->request('POST', $path, ['payload' => json_encode($body)], $token)
        );

        try {
            return $send($payload);
        } catch (\Throwable $e) {
            if ($this->isVideoRelatedError($e) && isset($payload['Request']['Product']['Attributes']['video'])) {
                unset($payload['Request']['Product']['Attributes']['video']);
                Log::warning('Lazada: upload dengan video gagal, mencoba ulang tanpa video: ' . $e->getMessage());

                return $send($payload);
            }

            throw $e;
        }
    }

    protected function withTokenRefresh(ChannelShop $shop, \Closure $callback): array
    {
        try {
            return $callback($shop->access_token);
        } catch (TokenExpiredException $e) {
            $this->authService->refreshStoreToken((string) $shop->id);
            $shop->refresh();

            return $callback($shop->access_token);
        }
    }

    protected function isVideoRelatedError(\Throwable $e): bool
    {
        if ($e instanceof TokenExpiredException) {
            return false;
        }

        return stripos($e->getMessage(), 'video') !== false;
    }

    protected function buildProductPayload(Product $product, ChannelShop $shop, ?array $attributeMapping = null): array
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

        $originalImageCount = count($imageUrls);
        $imageUrls = array_values(array_filter(array_map(
            fn ($url) => $migratedMap[$url] ?? null,
            $imageUrls
        )));

        if (count($imageUrls) < $originalImageCount) {
            Log::warning('Lazada: sebagian gambar produk gagal dimigrasikan, lanjut dengan gambar berkurang.', [
                'shop_id' => $shop->shop_id,
                'product_id' => $product->id,
                'expected' => $originalImageCount,
                'uploaded' => count($imageUrls),
            ]);
        }

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

        $config = config('channel.lazada_defaults', []);

        if (config('channel.lazada_video_enabled', false)) {
            $video = $product->media->firstWhere('media_type', 'video');
            if ($video && ! empty($video->url)) {
                $videoId = $this->imageUploader->uploadVideo($video->url, $shop->access_token, $imageUrls[0] ?? null);
                if ($videoId) {
                    $config['video_id'] = $videoId;
                }
            }
        }

        $channelCategoryUuid = $this->resolveChannelCategoryUuid($product);
        if ($channelCategoryUuid) {
            $attributes = $this->buildProductAttributes($product, $channelCategoryUuid);

            if (! empty($attributeMapping)) {
                $attributes = array_merge($attributes, $attributeMapping);
            }

            if (! empty($attributes)) {
                $config['attributes'] = $attributes;
            }
        }

        if (empty($imageUrls) && ! app()->runningUnitTests()) {
            throw new \RuntimeException('Gagal mengunggah gambar produk ke Lazada (minimal 1 gambar berformat JPG/PNG diperlukan). Format WebP tidak didukung Lazada.');
        }

        if (! $this->hasChannelCategory($product) && ! app()->runningUnitTests()) {
            throw new \RuntimeException('Kategori Lazada wajib diisi (mapping kategori untuk produk ini tidak ditemukan).');
        }

        return $this->outboundMapper->map($internal, $imageUrls, $config);
    }

    protected function hasChannelCategory(Product $product): bool
    {
        if (! $product->category_id) {
            return false;
        }

        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');
        if (! $channelId) {
            return false;
        }

        return DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $product->category_id)
            ->where('channel_categories.channel_id', $channelId)
            ->exists();
    }

    protected function resolveChannelCategoryUuid(Product $product): ?string
    {
        if (! $product->category_id) {
            return null;
        }

        $channelId = DB::table('channels')->where('code', 'lazada')->value('id');
        if (! $channelId) {
            return null;
        }

        return DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $product->category_id)
            ->where('channel_categories.channel_id', $channelId)
            ->value('channel_categories.id');
    }

    protected function buildProductAttributes(Product $product, string $channelCategoryUuid): array
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

            $value = null;
            if ($spec->attribute_option_id) {
                $optMapping = DB::table('attribute_option_channel_mappings')
                    ->where('attribute_option_id', $spec->attribute_option_id)
                    ->first();

                if ($optMapping) {
                    $value = DB::table('channel_attribute_options')
                        ->where('id', $optMapping->channel_attribute_option_id)
                        ->value('name');
                }
            } elseif ($spec->text_value) {
                $value = $spec->text_value;
            }

            if ($value !== null && $value !== '') {

                $attributes[(string) $channelAttr->external_id] = (string) $value;
            }
        }

        if (! app()->runningUnitTests()) {
            $provided = array_keys($attributes);

            $requiredAttrs = DB::table('channel_attributes')
                ->where('channel_category_id', $channelCategoryUuid)
                ->where('is_required', true)
                ->where('is_sale_prop', false)
                ->get(['external_id', 'name']);

            $missing = [];
            foreach ($requiredAttrs as $required) {
                if (! in_array((string) $required->external_id, $provided, true)) {
                    $missing[] = $required->name;
                }
            }

            if (! empty($missing)) {
                throw new \RuntimeException(
                    'Atribut wajib Lazada belum dipetakan/diisi: ' . implode(', ', $missing)
                        . '. Lengkapi atribut tersebut pada produk sebelum mengunggah ke Lazada.'
                );
            }
        }

        return $attributes;
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
