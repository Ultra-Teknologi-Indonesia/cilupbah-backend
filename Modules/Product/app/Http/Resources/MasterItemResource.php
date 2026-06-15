<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\ChannelUrlBuilder;

class MasterItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_group_id' => $this->id,
            'status' => $this->status,
            'is_po' => $this->order_type === 'PREORDER',
            'is_bundle' => (bool) $this->is_bundle,
            'item_name' => $this->name,
            'last_modified' => $this->updated_at,
            'variations' => $this->variations(),
            'sell_price' => $this->minSellPrice(),
            'item_category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'brand_name' => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'is_consignment' => (bool) $this->is_consignment,
            'variants' => $this->variantList(),
            'total_variants' => $this->resource->relationLoaded('variants') ? $this->variants->count() : 0,
            'online_status' => $this->onlineStatus(),
            'thumbnail' => $this->productThumbnail(),
        ];
    }

    protected function variations(): array
    {
        if (! $this->resource->relationLoaded('variationTypes')) {
            return [];
        }

        $valuesByAttribute = [];
        if ($this->resource->relationLoaded('variants')) {
            foreach ($this->variants as $variant) {
                if (! $variant->relationLoaded('options')) {
                    continue;
                }
                foreach ($variant->options as $option) {
                    $valuesByAttribute[$option->attribute_id][$option->value] = true;
                }
            }
        }

        return $this->variationTypes
            ->sortBy('sort_order')
            ->map(fn ($type) => [
                'label' => $type->attribute->name ?? null,
                'values' => array_keys($valuesByAttribute[$type->attribute_id] ?? []),
            ])
            ->values()
            ->all();
    }

    protected function variantList(): array
    {
        if (! $this->resource->relationLoaded('variants')) {
            return [];
        }

        return $this->variants->map(fn ($variant) => [
            'item_group_id' => $this->id,
            'item_id' => $variant->id,
            'item_code' => $variant->sku,
            'item_name' => $this->name,
            'is_bundle' => (bool) $this->is_bundle,
            'is_consignment' => (bool) $this->is_consignment,
            'variation_values' => $this->variationValues($variant),
            'is_internal' => $variant->is_internal,
            'barcode' => $variant->barcode,
            'tax_rate' => $variant->tax_rate !== null ? (float) $variant->tax_rate : null,
            'thumbnail' => $this->variantThumbnail($variant),
            'store_names' => $this->storeNames($variant),
            'sell_price' => $variant->sell_price !== null ? (float) $variant->sell_price : null,
            'sequence_item' => $variant->sequence_item,
        ])->values()->all();
    }

    protected function variationValues($variant): array
    {
        if (! $variant->relationLoaded('options')) {
            return [];
        }

        return $variant->options->map(fn ($option) => [
            'label' => $option->attribute->name ?? null,
            'value' => $option->value,
        ])->values()->all();
    }

    protected function storeNames($variant): array
    {
        if (! $variant->relationLoaded('channelMappings')) {
            return [];
        }

        return $variant->channelMappings
            ->map(function ($mapping) {
                $shop = ($mapping->relationLoaded('channelMapping') && $mapping->channelMapping
                    && $mapping->channelMapping->relationLoaded('channelShop'))
                    ? $mapping->channelMapping->channelShop
                    : null;

                return $shop ? ['store_name' => $shop->shop_name] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function onlineStatus(): array
    {
        if (! $this->resource->relationLoaded('channelMappings')) {
            return [];
        }

        return $this->channelMappings->map(function ($mapping) {
            $shop = $mapping->relationLoaded('channelShop') ? $mapping->channelShop : null;
            $channel = ($shop && $shop->relationLoaded('channel')) ? $shop->channel : null;

            $url = $mapping->channel_url ?: ChannelUrlBuilder::build(
                $channel->code ?? null,
                $mapping->external_product_id,
                $shop->shop_id ?? null,
            );

            return [
                'channel_id' => $shop->channel_id ?? null,
                'channel_code' => $channel->code ?? null,
                'channel_name' => $channel->name ?? null,
                'store_id' => $mapping->channel_shop_id,
                'store_name' => $shop->shop_name ?? null,
                'shop_id' => $shop->shop_id ?? null,
                'channel_group_id' => $mapping->external_product_id,
                'channel_url' => $url,
                'error_text' => $mapping->error_message,
            ];
        })->values()->all();
    }

    protected function minSellPrice(): ?float
    {
        if (! $this->resource->relationLoaded('variants')) {
            return null;
        }

        $prices = $this->variants
            ->pluck('sell_price')
            ->filter(fn ($price) => $price !== null);

        return $prices->isEmpty() ? null : (float) $prices->min();
    }

    protected function productThumbnail(): ?string
    {
        if (! $this->resource->relationLoaded('media')) {
            return null;
        }

        $productMedia = $this->media->whereNull('variant_id');
        $primary = $productMedia->firstWhere('is_primary', true)
            ?? $productMedia->first()
            ?? $this->media->first();

        return $primary->url ?? null;
    }

    protected function variantThumbnail($variant): ?string
    {
        if ($this->resource->relationLoaded('media')) {
            $variantMedia = $this->media->where('variant_id', $variant->id);
            $primary = $variantMedia->firstWhere('is_primary', true) ?? $variantMedia->first();

            if ($primary) {
                return $primary->url;
            }
        }

        return $this->productThumbnail();
    }
}
