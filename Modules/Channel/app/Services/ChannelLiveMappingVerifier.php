<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Product\Models\ProductVariantChannelMapping;

final class ChannelLiveMappingVerifier
{
    public function __construct(
        private readonly ShopeeClient $shopeeClient,
        private readonly TikTokProductService $tikTokProducts,
        private readonly LazadaProductService $lazadaProducts,
    ) {}

    /**
     * @return array{status: 'ok'|'mismatch'|'unreadable', issues: array<int, array<string, string>>}
     */
    public function inspect(
        string $channel,
        ChannelShop $shop,
        string $externalProductId,
        Collection $mappings,
    ): array {
        try {
            $remoteModels = $this->remoteModels($channel, $shop, $externalProductId);
        } catch (\Throwable $exception) {
            Log::warning('Push stok ditolak karena validasi model live gagal.', [
                'channel' => $channel,
                'shop_id' => $shop->shop_id,
                'external_product_id' => $externalProductId,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 'unreadable', 'issues' => []];
        }

        $issues = [];

        foreach ($mappings as $mapping) {
            if (! $mapping instanceof ProductVariantChannelMapping) {
                continue;
            }

            $modelId = trim((string) $mapping->external_sku_id);
            $remoteSku = trim((string) ($remoteModels[$modelId]['seller_sku'] ?? ''));
            $storedSku = trim((string) $mapping->channel_seller_sku);
            $masterSku = trim((string) ($mapping->variant?->sku ?? ''));
            $bundle = ChannelVariantMappingResolver::isBundleMapping($mapping);

            if ($modelId === ''
                || ! isset($remoteModels[$modelId])
                || $remoteSku === ''
                || $storedSku === ''
                || strcasecmp($remoteSku, $storedSku) !== 0
                || (! $bundle && strcasecmp($remoteSku, $masterSku) !== 0)) {
                $issues[] = [
                    'mapping_id' => (string) $mapping->id,
                    'model_id' => $modelId,
                    'remote_sku' => $remoteSku,
                    'stored_sku' => $storedSku,
                    'master_sku' => $masterSku,
                    'is_bundle' => $bundle ? 'true' : 'false',
                ];
            }
        }

        if ($issues === []) {
            return ['status' => 'ok', 'issues' => []];
        }

        Log::warning('Push stok ditolak karena mapping model live tidak cocok.', [
            'channel' => $channel,
            'shop_id' => $shop->shop_id,
            'external_product_id' => $externalProductId,
            'invalid_mappings' => $issues,
        ]);

        return ['status' => 'mismatch', 'issues' => $issues];
    }

    public function error(
        string $channel,
        ChannelShop $shop,
        string $externalProductId,
        Collection $mappings,
    ): ?string {
        $result = $this->inspect($channel, $shop, $externalProductId, $mappings);
        $label = match (strtolower($channel)) {
            'shopee' => 'Shopee',
            'tiktok' => 'TikTok',
            'lazada' => 'Lazada',
            default => ucfirst($channel),
        };

        return match ($result['status']) {
            'ok' => null,
            'unreadable' => "Validasi model {$label} gagal. Stok tidak dikirim agar tidak berubah pada varian yang salah.",
            default => "Mapping model {$label} berubah atau tidak cocok dengan SKU channel. Perbarui mapping sebelum sinkronisasi stok.",
        };
    }

    /** @return array<string, array{seller_sku: string}> */
    private function remoteModels(string $channel, ChannelShop $shop, string $externalProductId): array
    {
        return match (strtolower($channel)) {
            'shopee' => $this->shopeeModels($shop, $externalProductId),
            'tiktok' => $this->tikTokModels($shop, $externalProductId),
            'lazada' => $this->lazadaModels($shop, $externalProductId),
            default => throw new \InvalidArgumentException("Channel {$channel} tidak didukung."),
        };
    }

    /** @return array<string, array{seller_sku: string}> */
    private function shopeeModels(ChannelShop $shop, string $externalProductId): array
    {
        $response = $this->shopeeClient->request(
            'GET',
            '/api/v2/product/get_model_list',
            ['item_id' => (int) $externalProductId],
            $shop->access_token,
            $shop->shop_id,
        );

        return collect($response['response']['model'] ?? [])
            ->filter(static fn (array $model): bool => isset($model['model_id']))
            ->mapWithKeys(static fn (array $model): array => [
                (string) $model['model_id'] => [
                    'seller_sku' => trim((string) ($model['model_sku'] ?? '')),
                ],
            ])
            ->all();
    }

    /** @return array<string, array{seller_sku: string}> */
    private function tikTokModels(ChannelShop $shop, string $externalProductId): array
    {
        $product = $this->tikTokProducts->fetchLiveProduct(
            (string) $shop->shop_id,
            $externalProductId,
        );

        if (! is_array($product)) {
            throw new \RuntimeException('Listing TikTok tidak dapat dibaca.');
        }

        return collect($product['skus'] ?? [])
            ->filter(static fn (array $sku): bool => isset($sku['id']))
            ->mapWithKeys(static fn (array $sku): array => [
                (string) $sku['id'] => [
                    'seller_sku' => trim((string) ($sku['seller_sku'] ?? '')),
                ],
            ])
            ->all();
    }

    /** @return array<string, array{seller_sku: string}> */
    private function lazadaModels(ChannelShop $shop, string $externalProductId): array
    {
        $product = $this->lazadaProducts->fetchLiveProduct(
            (string) $shop->shop_id,
            $externalProductId,
        );

        if (! is_array($product)) {
            throw new \RuntimeException('Listing Lazada tidak dapat dibaca.');
        }

        return collect($product['skus'] ?? [])
            ->filter(static fn (array $sku): bool => isset($sku['SkuId']) || isset($sku['sku_id']))
            ->mapWithKeys(static fn (array $sku): array => [
                (string) ($sku['SkuId'] ?? $sku['sku_id']) => [
                    'seller_sku' => trim((string) ($sku['SellerSku'] ?? $sku['seller_sku'] ?? '')),
                ],
            ])
            ->all();
    }
}
