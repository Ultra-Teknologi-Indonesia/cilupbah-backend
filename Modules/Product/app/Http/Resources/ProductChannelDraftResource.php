<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Models\ProductChannelDraft;

class ProductChannelDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shop = $this->relationLoaded('channelShop') ? $this->channelShop : null;
        $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

        return [
            'id' => $this->id,
            'item_group_id' => $this->product_id,
            'item_group_name' => $this->whenLoaded('product', fn () => $this->product->name ?? null),
            'thumbnail' => $this->thumbnail(),
            'status' => $this->status,
            'can_upload' => $this->status !== ProductChannelDraft::STATUS_CANCELLED,
            'max' => $shop->shop_name ?? null,
            'channel_code' => $channel->code ?? null,
            'channel_name' => $channel->name ?? null,
            'channel_id' => $shop->channel_id ?? null,
            'store_id' => $this->channel_shop_id,
            'active_store' => $shop ? (bool) $shop->is_active : null,
            'channel_category_id' => $this->channel_category_id,
            'attribute_mapping' => $this->attribute_mapping,
            'price_override' => $this->price_override,
            'products' => $this->products(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function products(): array
    {
        if (! $this->relationLoaded('product') || ! $this->product || ! $this->product->relationLoaded('variants')) {
            return [];
        }

        $name = $this->product->name;

        return $this->product->variants
            ->map(fn ($variant) => [
                'item_name' => $name,
                'item_code' => $variant->sku,
            ])
            ->values()
            ->all();
    }

    protected function thumbnail(): ?string
    {
        if (! $this->relationLoaded('product') || ! $this->product || ! $this->product->relationLoaded('media')) {
            return null;
        }

        $media = $this->product->media;
        $primary = $media->whereNull('variant_id')->firstWhere('is_primary', true)
            ?? $media->whereNull('variant_id')->first()
            ?? $media->first();

        return $primary->url ?? null;
    }
}
