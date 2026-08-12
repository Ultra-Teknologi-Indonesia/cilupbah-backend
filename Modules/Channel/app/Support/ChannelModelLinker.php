<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Services\ProductService;

class ChannelModelLinker
{
    public function __construct(
        protected ChannelProductRepository $repository,
        protected ProductService $productService,
    ) {}

    public function link(
        object $shop,
        string $shopId,
        string $externalProductId,
        array $models,
        string $defaultProductId,
        string $defaultPcmId
    ): void {
        $known = $this->repository->variantsBySkus(array_map(
            fn ($m) => (string) ($m['sku'] ?? ''),
            $models
        ));

        $ownerByGroup = $this->resolveGroupOwners($models, $known);
        $pcmByProduct = [$defaultProductId => $defaultPcmId];

        foreach ($models as $model) {
            $sku = trim((string) ($model['sku'] ?? ''));

            if ($sku === '') {
                $fallbackVariantId = $model['fallback_variant_id'] ?? null;

                if ($fallbackVariantId) {

                    $this->repository->upsertVariantChannelMapping(
                        $defaultPcmId,
                        (string) $fallbackVariantId,
                        isset($model['external_sku_id']) ? (string) $model['external_sku_id'] : null,
                        null,
                        $model['price'] ?? null,
                        $model['sales_attribute_id'] ?? null,
                        $model['sales_attribute_name'] ?? null
                    );

                    continue;
                }

                $this->logSkipped($shop, $defaultProductId, $externalProductId, $model, 'Model tidak punya SKU di channel — isi SKU di Seller Center agar bisa dipetakan.');

                continue;
            }

            $variant = $known[$sku] ?? null;

            if ($variant) {
                $productId = (string) $variant->product_id;
                $variantId = (string) $variant->id;
            } else {
                $productId = $ownerByGroup[$this->groupKey($model)] ?? $defaultProductId;
                $variantId = $this->productService->addVariantFromChannel(
                    $productId,
                    ($model['variant'] ?? []) + ['sku' => $sku]
                );

                if (! $variantId) {
                    $this->logSkipped($shop, $productId, $externalProductId, $model, 'Varian baru gagal dibuat untuk SKU ' . $sku);

                    continue;
                }

                $known[$sku] = (object) ['id' => $variantId, 'sku' => $sku, 'product_id' => $productId];
            }

            if (! isset($pcmByProduct[$productId])) {
                $pcmByProduct[$productId] = $this->repository->upsertChannelMapping(
                    $productId,
                    $shopId,
                    $externalProductId,
                    'synced',
                    null,
                    false
                );
            }

            $this->repository->upsertVariantChannelMapping(
                $pcmByProduct[$productId],
                $variantId,
                isset($model['external_sku_id']) ? (string) $model['external_sku_id'] : null,
                $sku,
                $model['price'] ?? null,
                $model['sales_attribute_id'] ?? null,
                $model['sales_attribute_name'] ?? null
            );
        }
    }

    protected function resolveGroupOwners(array $models, array $known): array
    {
        $tally = [];

        foreach ($models as $model) {
            $sku = trim((string) ($model['sku'] ?? ''));
            $variant = $sku !== '' ? ($known[$sku] ?? null) : null;

            if (! $variant) {
                continue;
            }

            $key = $this->groupKey($model);
            $productId = (string) $variant->product_id;
            $tally[$key][$productId] = ($tally[$key][$productId] ?? 0) + 1;
        }

        $owners = [];

        foreach ($tally as $key => $counts) {
            arsort($counts);
            $owners[$key] = array_key_first($counts);
        }

        return $owners;
    }

    protected function groupKey(array $model): string
    {
        $group = $model['group'] ?? null;

        return $group === null || $group === '' ? '-' : (string) $group;
    }

    protected function logSkipped(object $shop, ?string $productId, string $externalProductId, array $model, string $reason): void
    {
        Log::warning('Model channel dilewati saat download', [
            'external_product_id' => $externalProductId,
            'external_sku_id' => $model['external_sku_id'] ?? null,
            'sku' => $model['sku'] ?? null,
            'alasan' => $reason,
        ]);

        ProductSyncLog::record([
            'product_id' => $productId,
            'channel_shop_id' => $shop->id,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'payload' => [
                'external_product_id' => $externalProductId,
                'external_sku_id' => $model['external_sku_id'] ?? null,
                'sku' => $model['sku'] ?? null,
                'group' => $model['group'] ?? null,
            ],
            'error_message' => $reason,
        ]);
    }
}
