<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariantChannelMapping;

class WooCommerceProductService
{
    public function __construct(
        protected WooCommerceClient $client,
        protected WooCommerceToInternalProductMapper $inboundMapper,
        protected ChannelProductRepository $productRepository,
    ) {}

    public function pullProducts(string $shopId, ?\Closure $onProgress = null): int
    {
        $shop = $this->requireShop($shopId);
        $productService = app(\Modules\Product\Services\ProductService::class);

        $total = 0;
        $count = 0;

        $this->client->paginate($shop, 'products', [], 100, 100, function (array $chunk) use ($shop, $shopId, $productService, $onProgress, &$total, &$count) {
            $total += count($chunk);

            foreach ($chunk as $item) {
                try {
                    $item = $this->hydrateVariations($shop, $item);

                    if ($this->persistItem($shop, $shopId, $item, $productService)) {
                        $count++;
                        if ($onProgress) {
                            $onProgress($count, max($total, $count));
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('WooCommerce: gagal pull produk ' . ($item['id'] ?? '?') . ': ' . $e->getMessage());

                    ProductSyncLog::record([
                        'channel_shop_id' => $shop->id,
                        'action' => ProductSyncLog::ACTION_DOWNLOAD,
                        'status' => ProductSyncLog::STATUS_FAILED,
                        'payload' => [
                            'external_product_id' => $item['id'] ?? null,
                            'title' => $item['name'] ?? null,
                        ],
                        'error_message' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $count;
    }

    public function pullProductById(string $shopId, string $externalProductId): bool
    {
        $shop = $this->requireShop($shopId);
        $productService = app(\Modules\Product\Services\ProductService::class);

        try {
            $item = $this->client->get($shop, "products/{$externalProductId}");
        } catch (\Throwable $e) {
            Log::error('WooCommerce: gagal ambil produk ' . $externalProductId . ': ' . $e->getMessage());

            return false;
        }

        if (empty($item['id'])) {
            return false;
        }

        $item = $this->hydrateVariations($shop, $item);

        if (! $this->persistItem($shop, $shopId, $item, $productService)) {
            return false;
        }

        ProductSyncLog::record([
            'channel_shop_id' => $shop->id,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
            'response' => ['external_product_id' => (string) ($item['id'] ?? $externalProductId)],
        ]);

        return true;
    }

    public function searchProducts(string $shopId, string $query): array
    {
        $shop = $this->requireShop($shopId);

        $products = $this->client->get($shop, 'products', ['search' => $query, 'per_page' => 50]);

        $results = [];
        foreach ($products as $item) {
            if (! is_array($item)) {
                continue;
            }
            $results[] = [
                'external_product_id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'seller_sku' => $item['sku'] ?? null,
                'price' => $item['regular_price'] ?? $item['price'] ?? null,
                'image' => $item['images'][0]['src'] ?? null,
                'shop_id' => $shopId,
                'shop_name' => $shop->shop_name ?? null,
                'channel_code' => 'woocommerce',
            ];
        }

        return $results;
    }

    public function reconcileChannelData(string $shopId): int
    {
        $shop = $this->requireShop($shopId);
        $channelShopId = $shop->id;

        $updated = 0;

        $this->client->paginate($shop, 'products', [], 100, 100, function (array $chunk) use ($shop, $channelShopId, &$updated) {
            foreach ($chunk as $item) {
                $mapping = ProductChannelMapping::where('external_product_id', (string) ($item['id'] ?? ''))
                    ->where('channel_shop_id', $channelShopId)
                    ->first();

                if (! $mapping) {
                    continue;
                }

                if (strtolower((string) ($item['type'] ?? 'simple')) === 'variable') {
                    foreach ($this->fetchVariations($shop, (string) ($item['id'] ?? '')) as $variation) {
                        if (empty($variation['id'])) {
                            continue;
                        }

                        $vm = ProductVariantChannelMapping::where('product_channel_mapping_id', $mapping->id)
                            ->where('external_sku_id', (string) $variation['id'])
                            ->first();
                        if (! $vm) {
                            continue;
                        }

                        $vmUpdate = [];
                        if (! empty($variation['sku'])) {
                            $vmUpdate['channel_seller_sku'] = $variation['sku'];
                        }
                        $price = $variation['regular_price'] ?? $variation['price'] ?? null;
                        if ($price !== null && $price !== '') {
                            $vmUpdate['synced_price'] = $price;
                        }
                        if ($vmUpdate) {
                            $vm->update($vmUpdate);
                        }
                    }
                } else {
                    $price = $item['regular_price'] ?? $item['price'] ?? null;
                    if ($price !== null && $price !== '') {
                        ProductVariantChannelMapping::where('product_channel_mapping_id', $mapping->id)
                            ->update(['synced_price' => $price]);
                    }
                }

                $updated++;
            }
        });

        return $updated;
    }

    protected function hydrateVariations(ChannelShop $shop, array $item): array
    {
        if (strtolower((string) ($item['type'] ?? 'simple')) !== 'variable') {
            return $item;
        }

        $productId = (string) ($item['id'] ?? '');
        if ($productId === '') {
            return $item;
        }

        try {
            $item['variations'] = $this->fetchVariations($shop, $productId);
        } catch (\Throwable $e) {
            Log::warning("WooCommerce get variations gagal produk {$productId}: " . $e->getMessage());
            $item['variations'] = [];
        }

        return $item;
    }

    protected function fetchVariations(ChannelShop $shop, string $productId): array
    {
        if ($productId === '') {
            return [];
        }

        return $this->client->paginate($shop, "products/{$productId}/variations");
    }

    protected function persistItem(ChannelShop $shop, string $shopId, array $item, $productService): bool
    {
        $matchedExisting = false;
        $variantIds = [];
        $internalData = app(ChannelAssetImporter::class)->import($this->inboundMapper->map($item, $shopId));
        $insertedId = $productService->upsertFromChannel($internalData, $matchedExisting, $variantIds);
        if (! $insertedId) {
            return false;
        }

        $pcmId = $this->productRepository->upsertChannelMapping(
            (string) $insertedId,
            $shopId,
            (string) ($item['id'] ?? ''),
            'synced',
            null,
            false
        );

        $variations = $item['variations'] ?? [];

        if (empty($variations)) {
            if (! $matchedExisting) {
                $variantId = $variantIds[0] ?? null;
            } else {
                $sku = $item['sku'] ?? null;
                $variant = $sku ? $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku) : null;
                $variantId = $variant->id ?? null;
            }

            if ($variantId) {
                $this->productRepository->upsertVariantChannelMapping(
                    $pcmId,
                    $variantId,
                    null,
                    $item['sku'] ?? null,
                    $item['regular_price'] ?? $item['price'] ?? null
                );
            }

            return true;
        }

        foreach ($variations as $idx => $variation) {
            if (! $matchedExisting) {
                $variantId = $variantIds[$idx] ?? null;
            } else {
                $sku = $variation['sku'] ?? null;
                $variant = $sku ? $this->productRepository->getVariantByProductIdAndSku((string) $insertedId, $sku) : null;
                $variantId = $variant->id ?? null;
            }

            if (! $variantId) {
                continue;
            }

            $this->productRepository->upsertVariantChannelMapping(
                $pcmId,
                $variantId,
                isset($variation['id']) ? (string) $variation['id'] : null,
                $variation['sku'] ?? null,
                $variation['regular_price'] ?? $variation['price'] ?? null
            );
        }

        return true;
    }

    protected function requireShop(string $shopId): ChannelShop
    {
        $shop = ChannelShop::where('shop_id', $shopId)->whereNull('disconnected_at')->first();

        if (! $shop) {
            throw new \RuntimeException('Toko tidak ditemukan', 422);
        }

        return $shop;
    }
}
