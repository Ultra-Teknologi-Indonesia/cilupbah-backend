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

        if (in_array((string)$status, ['5', '6'])) {
            $mapping->update(['sync_status' => 'deactivated']);
        } elseif ((string)$status === '4') {
            $mapping->update(['sync_status' => 'synced']);
        } elseif ((string)$status === '3') {
            $mapping->update(['sync_status' => 'failed', 'error_message' => $data['suspend_reason'] ?? 'Rejected by platform']);
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
