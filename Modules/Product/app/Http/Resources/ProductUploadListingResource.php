<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris = satu toko tujuan untuk satu produk (tab Belum/Sudah Diupload).
 * Resource membungkus ChannelShop; data produk + mapping disuntik service via
 * setAttribute('item_group_id'/'item_group_name') dan setRelation('productMapping').
 */
class ProductUploadListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $channel = $this->resource->relationLoaded('channel') ? $this->channel : null;
        $mapping = $this->resource->relationLoaded('productMapping')
            ? $this->resource->getRelation('productMapping')
            : null;

        return [
            'item_group_id' => $this->getAttribute('item_group_id'),
            'item_group_name' => $this->getAttribute('item_group_name'),
            'store_id' => $this->id,
            'shop_id' => $this->shop_id,
            'store_name' => $this->shop_name,
            'channel_id' => $this->channel_id,
            'channel_code' => $channel->code ?? null,
            'channel_name' => $channel->name ?? null,
            'is_uploaded' => $mapping !== null,
            'channel_group_id' => $mapping->external_product_id ?? null,
            'sync_status' => $mapping->sync_status ?? null,
            'product_channels' => $this->productChannels($mapping),
        ];
    }

    protected function productChannels($mapping): array
    {
        if (! $mapping || ! $mapping->relationLoaded('variantMappings')) {
            return [];
        }

        return $mapping->variantMappings->map(function ($vm) {
            $variant = $vm->relationLoaded('variant') ? $vm->variant : null;

            return [
                'master_sku' => $variant->sku ?? null,
                'channel_sku' => $vm->external_sku_id ?: ($variant->sku ?? null),
                'variation' => $this->variationLabel($variant),
            ];
        })->values()->all();
    }

    protected function variationLabel($variant): ?string
    {
        if (! $variant || ! $variant->relationLoaded('options')) {
            return null;
        }

        $values = $variant->options->map(fn ($o) => $o->value)->filter()->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }
}
