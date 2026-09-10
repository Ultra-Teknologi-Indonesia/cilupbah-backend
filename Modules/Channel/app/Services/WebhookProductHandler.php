<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\SyncStockToChannelsJob;
use Modules\Product\Models\ProductChannelMapping;

class WebhookProductHandler
{
    public function __construct(
        protected ChannelDownloadService $downloadService,
    ) {}

    public function handleProductStatusChange(array $data, string $shopId): void
    {
        $externalProductId = $data['product_id'] ?? null;

        if (! $externalProductId) {
            return;
        }

        $mapping = ProductChannelMapping::whereHas('channelShop', function ($q) use ($shopId) {
            $q->where('shop_id', $shopId);
        })->where('external_product_id', $externalProductId)->first();

        if (! $mapping) {
            Log::info("TikTok Webhook Product Status Change: Mapping not found for external_id {$externalProductId}");

            return;
        }

        $status = strtoupper((string) ($data['status'] ?? ($data['audit']['status'] ?? '')));

        match (true) {
            in_array($status, ['APPROVED', 'PRODUCT_FIRST_PASS_REVIEW', '4', 'LIVE', 'ACTIVATE'], true) => $mapping->markApproved(),
            in_array($status, ['FAILED', 'PRODUCT_AUDIT_FAILURE', '3', 'SUSPENDED', '6', '7'], true) => $mapping->markRejected($this->rejectionReason($data)),
            in_array($status, ['AUDITING', 'PRE_APPROVED', '2', 'PENDING'], true) => $mapping->markInReview(),
            in_array($status, ['NONE', 'DEACTIVATED', '5', '8', 'DELETED'], true) => $mapping->update(['sync_status' => ProductChannelMapping::STATUS_DEACTIVATED]),
            default => null,
        };

        try {
            $this->downloadService->downloadProductDebounced('tiktok', $shopId, (string) $externalProductId);
        } catch (\Throwable $e) {
            Log::warning('TikTok product status re-sync gagal: '.$e->getMessage(), ['product_id' => $externalProductId]);
        }
    }

    private function rejectionReason(array $data): string
    {
        $reason = $data['suspended_reason']
            ?? $data['suspend_reason']
            ?? $data['audit_failed_reasons']
            ?? 'Ditolak platform';

        return is_array($reason) ? json_encode($reason) : (string) $reason;
    }

    public function handleProductUpdate(array $data, string $shopId): void
    {
        $externalProductId = $data['product_id'] ?? null;

        if (! $externalProductId) {
            return;
        }

        $mapping = ProductChannelMapping::whereHas('channelShop', function ($q) use ($shopId) {
            $q->where('shop_id', $shopId);
        })->where('external_product_id', $externalProductId)->first();

        if (! $mapping) {
            Log::info("TikTok Webhook Product Update: Mapping not found for external_id {$externalProductId}");

            return;
        }

        $variantMappings = $mapping->variantMappings()
            ->get()
            ->mapWithKeys(fn ($variantMapping) => [
                (string) $variantMapping->external_sku_id => $variantMapping,
            ]);

        try {
            $this->downloadService->downloadProductDebounced('tiktok', $shopId, (string) $externalProductId);
        } catch (\Throwable $e) {
            Log::warning('TikTok re-sync produk gagal: '.$e->getMessage(), ['product_id' => $externalProductId]);
        }

        if (! empty($data['skus'])) {
            foreach ($data['skus'] as $skuData) {
                $externalSkuId = $skuData['id'] ?? null;
                if ($externalSkuId === null) {
                    continue;
                }

                $variantMapping = $variantMappings->get((string) $externalSkuId);
                if ($variantMapping) {

                    $update = [];
                    if (! empty($skuData['seller_sku'])) {
                        $update['channel_seller_sku'] = $skuData['seller_sku'];
                    }
                    $price = $skuData['price']['tax_exclusive_price'] ?? $skuData['price']['sale_price'] ?? null;
                    if ($price !== null) {
                        $update['synced_price'] = $price;
                    }
                    $newStock = (int) ($skuData['inventory'][0]['quantity'] ?? 0);
                    $stockChanged = $newStock !== (int) $variantMapping->synced_stock;

                    if ($stockChanged) {
                        $update['synced_stock'] = $newStock;
                    }

                    if ($update) {
                        $variantMapping->update($update);
                    }

                    if ($stockChanged) {
                        SyncStockToChannelsJob::dispatch($variantMapping->variant_id, $mapping->channel_shop_id);
                    }
                }
            }
        }
    }
}
