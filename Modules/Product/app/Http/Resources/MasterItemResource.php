<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\ChannelUrlBuilder;

class MasterItemResource extends JsonResource
{
    protected ?array $shopNames = null;

    protected ?array $thumbnailsByVariant = null;

    public function toArray(Request $request): array
    {
        return [
            'item_group_id' => $this->id,
            'status' => $this->status,
            'is_po' => $this->order_type === 'PREORDER',
            'is_bundle' => (bool) $this->is_bundle,
            'sku' => $this->sku,
            'item_name' => $this->name,
            'last_modified' => $this->updated_at,
            'variations' => $this->variations(),
            'sell_price' => $this->minSellPrice(),
            'item_category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'is_consignment' => (bool) $this->is_consignment,
            'variants' => $this->variantList(),
            'total_variants' => $this->resource->relationLoaded('variants') ? $this->variants->count() : 0,
            'online_status' => $this->onlineStatus(),
            'thumbnail' => $this->productThumbnail(),
            'is_merged' => (bool) ($this->is_merged ?? false),
            'master_name' => $this->merge_master_name ?? null,
            'member_ids' => $this->merge_member_ids ?? [$this->id],
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
                'label' => $type->relationLoaded('attribute') ? ($type->attribute->name ?? null) : null,
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
            'label' => $option->relationLoaded('attribute') ? ($option->attribute->name ?? null) : null,
            'value' => $option->value,
        ])->values()->all();
    }

    protected function storeNames($variant): array
    {
        if (! $variant->relationLoaded('channelMappings')) {
            return [];
        }

        $shopNames = $this->shopNamesByMappingId();

        return $variant->channelMappings
            ->map(fn ($mapping) => array_key_exists($mapping->product_channel_mapping_id, $shopNames)
                ? ['store_name' => $shopNames[$mapping->product_channel_mapping_id]]
                : null)
            ->filter()
            ->values()
            ->all();
    }

    protected function shopNamesByMappingId(): array
    {
        if ($this->shopNames !== null) {
            return $this->shopNames;
        }

        if (! $this->resource->relationLoaded('channelMappings')) {
            return $this->shopNames = [];
        }

        $map = [];
        foreach ($this->channelMappings as $mapping) {
            $shop = $mapping->relationLoaded('channelShop') ? $mapping->channelShop : null;

            if ($shop) {
                $map[$mapping->id] = $shop->shop_name;
            }
        }

        return $this->shopNames = $map;
    }

    protected function onlineStatus(): array
    {
        if (! $this->resource->relationLoaded('channelMappings')) {
            return [];
        }

        return $this->channelMappings->filter(function ($mapping) {
            if (! $mapping->external_product_id && in_array($mapping->sync_status, ['failed', 'pending'])) {
                return false;
            }

            return true;
        })->map(function ($mapping) {
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

        return $this->thumbnailsByVariant()[''] ?? $this->media->first()->url ?? null;
    }

    protected function variantThumbnail($variant): ?string
    {
        if (! $this->resource->relationLoaded('media')) {
            return null;
        }

        return $this->thumbnailsByVariant()[$variant->id] ?? null;
    }

    protected function thumbnailsByVariant(): array
    {
        if ($this->thumbnailsByVariant !== null) {
            return $this->thumbnailsByVariant;
        }

        $thumbnails = [];
        $primary = [];

        foreach ($this->media as $item) {
            $key = $item->variant_id ?? '';

            if (! array_key_exists($key, $thumbnails)) {
                $thumbnails[$key] = $item->url;
            }

            if ($item->is_primary && ! isset($primary[$key])) {
                $primary[$key] = $item->url;
            }
        }

        return $this->thumbnailsByVariant = $primary + $thumbnails;
    }
}
