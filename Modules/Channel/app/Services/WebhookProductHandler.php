<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Channel\Jobs\SyncStockToChannelsJob;

class WebhookProductHandler
{

    public function handleProductStatusChange(array $data, string $shopId): void
    {
        $externalProductId = $data['product_id'] ?? null;
        $status = $data['status'] ?? null;

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

        // Status produk TikTok: 2=pending(review), 3=failed(ditolak),
        // 4=live(disetujui), 5/6=deactivated.
        if (in_array((string) $status, ['5', '6'], true)) {
            $mapping->update(['sync_status' => ProductChannelMapping::STATUS_DEACTIVATED]);
        } elseif ((string) $status === '4') {
            $mapping->markApproved();
        } elseif ((string) $status === '3') {
            $reason = $data['suspend_reason'] ?? $data['audit_failed_reasons'] ?? 'Ditolak platform';
            $mapping->markRejected(is_array($reason) ? json_encode($reason) : (string) $reason);
        } elseif ((string) $status === '2') {
            $mapping->markInReview();
        }
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

        if (!empty($data['skus'])) {
            foreach ($data['skus'] as $skuData) {
                $variantMapping = $mapping->variantMappings()->where('external_sku_id', $skuData['id'])->first();
                if ($variantMapping) {
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
