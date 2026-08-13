<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelProductRepository;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Support\ChannelSku;
use Modules\Product\Services\MasterProductMerger;
use Modules\Product\Services\ProductService;

class ChannelModelLinker
{
    public function __construct(
        protected ChannelProductRepository $repository,
        protected ProductService $productService,
        protected MasterProductMerger $merger,
    ) {}

    public function link(
        object $shop,
        string $shopId,
        string $externalProductId,
        array $models,
        string $defaultProductId,
        string $defaultPcmId
    ): void {
        $known = $this->repository->variantsBySkus(array_values(array_filter(array_map(
            fn ($m) => ChannelSku::normalize($m['sku'] ?? null, $externalProductId),
            $models
        ))));

        $productId = $this->consolidate($shop, $externalProductId, $known, $defaultProductId);

        $pcmId = $productId === $defaultProductId
            ? $defaultPcmId
            : $this->repository->upsertChannelMapping($productId, $shopId, $externalProductId, 'synced', null, false);

        foreach ($models as $model) {
            $sku = ChannelSku::normalize($model['sku'] ?? null, $externalProductId);

            if ($sku === null) {
                $this->logSkipped(
                    $shop,
                    $productId,
                    $externalProductId,
                    $model,
                    ChannelSku::reason($model['sku'] ?? null, $externalProductId)
                );

                continue;
            }

            $variant = $known[$sku] ?? null;

            if ($variant) {
                $variantId = (string) $variant->id;

                $this->productService->backfillVariantOptionsFromChannel(
                    $productId,
                    $variantId,
                    $model['variant']['options'] ?? []
                );
            } else {
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

            $this->repository->upsertVariantChannelMapping(
                $pcmId,
                $variantId,
                isset($model['external_sku_id']) ? (string) $model['external_sku_id'] : null,
                $sku,
                $model['price'] ?? null,
                $model['sales_attribute_id'] ?? null,
                $model['sales_attribute_name'] ?? null
            );
        }

        $this->dropStaleListingMappings($shop, $externalProductId, $pcmId);
    }

    protected function consolidate(object $shop, string $externalProductId, array &$known, string $defaultProductId): string
    {
        $owners = [$defaultProductId];

        foreach ($known as $variant) {
            $owners[] = (string) $variant->product_id;
        }

        $owners = array_values(array_unique($owners));

        if (count($owners) < 2) {
            return $defaultProductId;
        }

        $winner = $this->merger->resolveWinner($owners) ?? $defaultProductId;

        $moving = [];

        foreach ($known as $variant) {
            if ((string) $variant->product_id !== $winner) {
                $moving[] = (string) $variant->id;
            }
        }

        if (! $moving) {
            return $winner;
        }

        $moved = $this->merger->moveVariants($winner, $moving);

        foreach ($known as $sku => $variant) {
            $known[$sku]->product_id = $winner;
        }

        Log::info('Varian dikonsolidasikan ke satu master saat download', [
            'external_product_id' => $externalProductId,
            'master_tujuan' => $winner,
            'varian_pindah' => $moved,
            'master_terlibat' => $owners,
        ]);

        ProductSyncLog::record([
            'product_id' => $winner,
            'channel_shop_id' => $shop->id,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
            'payload' => [
                'external_product_id' => $externalProductId,
                'master_tujuan' => $winner,
                'master_terlibat' => $owners,
                'varian_pindah' => $moved,
            ],
        ]);

        return $winner;
    }

    protected function dropStaleListingMappings(object $shop, string $externalProductId, string $keepPcmId): void
    {
        $stale = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', $externalProductId)
            ->where('id', '!=', $keepPcmId)
            ->pluck('id')
            ->all();

        if (! $stale) {
            return;
        }

        DB::table('product_variant_channel_mappings')->whereIn('product_channel_mapping_id', $stale)->delete();
        DB::table('product_channel_mappings')->whereIn('id', $stale)->delete();

        Log::info('Mapping listing ganda dibersihkan', [
            'external_product_id' => $externalProductId,
            'dihapus' => count($stale),
        ]);
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
