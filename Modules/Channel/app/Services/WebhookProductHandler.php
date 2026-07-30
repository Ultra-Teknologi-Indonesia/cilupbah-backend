<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Channel\Jobs\SyncStockToChannelsJob;

class WebhookProductHandler
{
    public function __construct(
        protected ChannelDownloadService $downloadService,
    ) {}

    public function handleProductStatusChange(array $data, string $shopId): void
    {
        $externalProductId = $data['product_id'] ?? null;

        if (!$externalProductId) {
            return;
        }

        $mapping = ProductChannelMapping::whereHas('channelShop', function($q) use ($shopId) {
            $q->where('shop_id', $shopId);
        })->where('external_product_id', $externalProductId)->first();

        if (!$mapping) {
            Log::info("TikTok Webhook Product Status Change: Mapping not found for external_id {$externalProductId}");
            return;
        }

        $status = strtoupper((string) ($data['status'] ?? ($data['audit']['status'] ?? '')));

        match (true) {
            in_array($status, ['APPROVED', 'PRODUCT_FIRST_PASS_REVIEW'], true) => $mapping->markApproved(),
            in_array($status, ['FAILED', 'PRODUCT_AUDIT_FAILURE'], true)
                => $mapping->markRejected($this->rejectionReason($data)),
            in_array($status, ['AUDITING', 'PRE_APPROVED'], true) => $mapping->markInReview(),
            $status === 'NONE' => $mapping->update(['sync_status' => ProductChannelMapping::STATUS_DEACTIVATED]),
            default => null, 
        };

        try {
            $this->downloadService->downloadProductDebounced('tiktok', $shopId, (string) $externalProductId);
        } catch (\Throwable $e) {
            Log::warning('TikTok product status re-sync gagal: ' . $e->getMessage(), ['product_id' => $externalProductId]);
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

        if (!$externalProductId) {
            return;
        }

        $mapping = ProductChannelMapping::whereHas('channelShop', function($q) use ($shopId) {
            $q->where('shop_id', $shopId);
        })->where('external_product_id', $externalProductId)->first();

        if (!$mapping) {
            Log::info("TikTok Webhook Product Update: Mapping not found for external_id {$externalProductId}");
            return;
        }

        try {
            $this->downloadService->downloadProductDebounced('tiktok', $shopId, (string) $externalProductId);
        } catch (\Throwable $e) {
            Log::warning('TikTok re-sync produk gagal: ' . $e->getMessage(), ['product_id' => $externalProductId]);
        }

        if (!empty($data['skus'])) {
            foreach ($data['skus'] as $skuData) {
                $variantMapping = $mapping->variantMappings()->where('external_sku_id', $skuData['id'])->first();
                if ($variantMapping) {

                    $update = [];
                    if (!empty($skuData['seller_sku'])) {
                        $update['channel_seller_sku'] = $skuData['seller_sku'];
                    }
                    $price = $skuData['price']['tax_exclusive_price'] ?? $skuData['price']['sale_price'] ?? null;
                    if ($price !== null) {
                        $update['synced_price'] = $price;
                    }
                    if ($update) {
                        $variantMapping->update($update);
                    }

                    $newStock = (int) ($skuData['inventory'][0]['quantity'] ?? 0);

                    if ($newStock !== $variantMapping->synced_stock) {
                        $variantMapping->update(['synced_stock' => $newStock]);

                        SyncStockToChannelsJob::dispatch($variantMapping->variant_id, $mapping->channel_shop_id);
                    }
                }
            }
        }
    }
}
