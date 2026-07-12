<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventorySyncMatrixResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id'          => $this->id,
            'item_code'        => $this->sku,
            'item_name'        => $this->whenLoaded('product', fn () => $this->product?->name),
            'item_group_id'    => $this->product_id,
            'is_bundle'        => $this->whenLoaded('product', fn () => (bool) $this->product?->is_bundle, false),
            'variation_values' => $this->variationValues(),
            'thumbnail'        => $this->resolveThumbnail(),
            'stores'           => $this->stores(),
        ];
    }

    protected function stores(): array
    {
        if (! $this->relationLoaded('channelMappings')) {
            return [];
        }

        return $this->channelMappings
            ->filter(fn ($mapping) => $mapping->channelMapping !== null)
            ->map(fn ($mapping) => [
                'channel_shop_id' => $mapping->channelMapping->channel_shop_id,
                'has_listing'     => true,
                'sync_enabled'    => (bool) $mapping->sync_enabled,
                'external_sku_id' => $mapping->external_sku_id,
                'sync_status'     => $mapping->channelMapping->sync_status,
            ])
            ->values()
            ->all();
    }

    protected function variationValues(): array
    {
        if (! $this->relationLoaded('options')) {
            return [];
        }

        return $this->options->map(fn ($opt) => [
            'label' => $opt->relationLoaded('attribute') ? $opt->attribute?->name : null,
            'value' => $opt->value,
        ])->values()->toArray();
    }

    protected function resolveThumbnail(): ?string
    {
        if ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $primary = $this->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->media->first()->url;
        }

        if ($this->relationLoaded('product') && $this->product?->relationLoaded('media') && $this->product->media->isNotEmpty()) {
            $primary = $this->product->media->firstWhere('is_primary', true);

            return $primary ? $primary->url : $this->product->media->first()->url;
        }

        return null;
    }
}
