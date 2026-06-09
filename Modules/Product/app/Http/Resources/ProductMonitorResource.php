<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource untuk satu produk di tab Pantauan.
 * Menyertakan data channel (PCM) dan SKU tersinkron (PVCM) per channel.
 */
class ProductMonitorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primaryImage = null;
        if ($this->resource->relationLoaded('media')) {
            $primaryImage = $this->media->firstWhere('is_primary', true)?->url
                ?? $this->media->first()?->url;
        }

        $channels = [];
        if ($this->resource->relationLoaded('channelMappings')) {
            $channels = $this->channelMappings->map(function ($mapping) {
                $shop    = $mapping->relationLoaded('channelShop') ? $mapping->channelShop : null;
                $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

                $skus = [];
                if ($mapping->relationLoaded('variantMappings')) {
                    $skus = $mapping->variantMappings->map(function ($vm) {
                        $variant = $vm->relationLoaded('variant') ? $vm->variant : null;

                        return [
                            'variant_id'      => $vm->variant_id,
                            'sku'             => $variant->sku ?? null,
                            'external_sku_id' => $vm->external_sku_id,
                            'sell_price'      => $variant?->sell_price !== null ? (float) $variant->sell_price : null,
                            'override_price'  => $vm->override_price !== null ? (float) $vm->override_price : null,
                            'synced_price'    => $vm->synced_price !== null ? (float) $vm->synced_price : null,
                            'synced_stock'    => $vm->synced_stock,
                        ];
                    })->values()->all();
                }

                return [
                    'channel_shop_id'     => $mapping->channel_shop_id,
                    'shop_id'             => $shop?->shop_id,
                    'shop_name'           => $shop?->shop_name,
                    'channel'             => $channel?->name,
                    'channel_code'        => $channel?->code,
                    'external_product_id' => $mapping->external_product_id,
                    'sync_status'         => $mapping->sync_status,
                    'error_message'       => $mapping->error_message,
                    'last_synced_at'      => $mapping->last_synced_at,
                    'skus'                => $skus,
                ];
            })->values()->all();
        }

        return [
            'product_id'     => $this->id,
            'product_name'   => $this->name,
            'sku'            => $this->sku,
            'product_status' => $this->status,
            'primary_image'  => $primaryImage,
            'channels'       => $channels,
            'updated_at'     => $this->updated_at,
        ];
    }
}
