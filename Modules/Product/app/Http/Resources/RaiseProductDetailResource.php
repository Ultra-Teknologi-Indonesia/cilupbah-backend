<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\ChannelUrlBuilder;

class RaiseProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mapping = $this->resource->relationLoaded('channelMapping') ? $this->channelMapping : null;
        $product = ($mapping && $mapping->relationLoaded('product')) ? $mapping->product : null;
        $shop = ($mapping && $mapping->relationLoaded('channelShop')) ? $mapping->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

        $variants = ($product && $product->relationLoaded('variants')) ? $product->variants : collect();
        $media = ($product && $product->relationLoaded('media')) ? $product->media : collect();

        $url = ($mapping?->channel_url) ?: ChannelUrlBuilder::build(
            $channel->code ?? null,
            $mapping->external_product_id ?? null,
            $shop->shop_id ?? null,
        );

        return [
            'raiseproduct_detail_id' => $this->id,
            'raiseproduct_id' => $this->raise_product_id,
            'item_group_name' => $product->name ?? null,
            'channel_group_id' => $mapping->external_product_id ?? null,
            'channel_url' => $url,
            'item_id' => $variants->pluck('id')->values()->all(),
            'item_codes' => $variants->pluck('sku')->values()->all(),
            'thumbnails' => $variants->map(fn ($variant) => $this->variantThumbnail($variant, $media))->values()->all(),
            'is_active' => (bool) $this->is_active,
            'is_repeatable' => (bool) $this->is_repeatable,
            'is_success' => $this->is_success,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'reason' => $this->reason,
        ];
    }

    protected function variantThumbnail($variant, $media): ?string
    {
        if ($media->isEmpty()) {
            return null;
        }

        $variantMedia = $media->where('variant_id', $variant->id);
        $primary = $variantMedia->firstWhere('is_primary', true)
            ?? $variantMedia->first()
            ?? $media->firstWhere('is_primary', true)
            ?? $media->first();

        return $primary->url ?? null;
    }
}
