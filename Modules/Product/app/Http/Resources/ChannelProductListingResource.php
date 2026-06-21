<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\ChannelUrlBuilder;

class ChannelProductListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->resource->relationLoaded('channelShop') ? $this->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

        $url = $this->channel_url ?: ChannelUrlBuilder::build(
            $channel->code ?? null,
            $this->external_product_id,
            $shop->shop_id ?? null,
        );

        $connections = $this->connections($shop, $channel);

        return [
            'mapping_id' => $this->id,
            'channel_group_id' => $this->external_product_id,
            'store_id' => $this->channel_shop_id,
            'shop_id' => $shop->shop_id ?? null,
            'item_group_name' => $this->relationLoaded('product') ? ($this->product->name ?? null) : null,
            'min' => $shop->shop_name ?? null,
            'channel_id' => $shop->channel_id ?? null,
            'channel_code' => $channel->code ?? null,
            'channel_url' => $url,
            'sync_status' => $this->sync_status,
            'error_message' => $this->error_message,
            'has_product_data' => count($connections) > 0,
            'product' => $connections,
        ];
    }

    protected function connections($shop, $channel): array
    {
        if (! $this->resource->relationLoaded('variantMappings')) {
            return [];
        }

        $media = ($this->relationLoaded('product') && $this->product && $this->product->relationLoaded('media'))
            ? $this->product->media
            : collect();

        $primary = $media->firstWhere('is_primary', true) ?? $media->first();

        return $this->variantMappings->map(function ($mapping) use ($shop, $channel, $media, $primary) {
            $variant = $mapping->relationLoaded('variant') ? $mapping->variant : null;
            $price = $mapping->override_price ?? ($variant->sell_price ?? null);

            $variantMedia = ($variant && $media->isNotEmpty())
                ? ($media->where('variant_id', $variant->id)->firstWhere('is_primary', true)
                    ?? $media->where('variant_id', $variant->id)->first())
                : null;

            return [
                'store_id' => $this->channel_shop_id,
                'max_price' => $price !== null ? (float) $price : null,
                'min_price' => $price !== null ? (float) $price : null,
                'thumbnail' => $variantMedia->url ?? ($primary->url ?? null),
                'channel_id' => $shop->channel_id ?? null,
                'master_sku' => $variant->sku ?? null,
                'store_name' => $shop->shop_name ?? null,
                'channel_sku' => $mapping->external_sku_id ?: ($variant->sku ?? null),
                'product_variation' => $this->variationLabel($variant),
            ];
        })->values()->all();
    }

    protected function variationLabel($variant): ?string
    {
        if (! $variant || ! $variant->relationLoaded('options')) {
            return null;
        }

        $values = $variant->options
            ->map(fn ($option) => $option->value)
            ->filter()
            ->values();

        return $values->isEmpty() ? null : $values->implode(', ');
    }
}
