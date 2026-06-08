<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'primary_image' => $this->primaryImageUrl(),
            'price_range' => $this->priceRange(),
            'channels_count' => $this->when(
                $this->resource->relationLoaded('channelMappings'),
                fn () => $this->channelMappings->count()
            ),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
            'is_bundle' => $this->is_bundle,
            'is_consignment' => $this->is_consignment,
            'channel_mappings' => $this->whenLoaded('channelMappings', function () {
                return $this->channelMappings->map(function ($mapping) {
                    $shop = $mapping->relationLoaded('channelShop') ? $mapping->channelShop : null;

                    return [
                        'channel_shop_id' => $mapping->channel_shop_id,
                        'shop_name' => $shop->shop_name ?? null,
                        'channel_name' => ($shop && $shop->relationLoaded('channel') && $shop->channel)
                            ? $shop->channel->name
                            : null,
                        'external_product_id' => $mapping->external_product_id,
                        'sync_status' => $mapping->sync_status,
                        'last_synced_at' => $mapping->last_synced_at,
                    ];
                });
            }),
            'variants' => $this->whenLoaded('variants', function () {
                return $this->variants->map(function ($variant) {
                    return [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'sell_price' => $variant->sell_price,
                        'is_active' => $variant->is_active,
                    ];
                });
            }),
            'verified_at' => $this->verified_at,
            'archived_at' => $this->archived_at,
            'archive_reason' => $this->archive_reason,
            'archived_by' => $this->whenLoaded('archivedBy', fn () => $this->archivedBy ? [
                'id' => $this->archivedBy->id,
                'name' => $this->archivedBy->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * URL gambar utama: media is_primary, fallback ke media pertama.
     */
    protected function primaryImageUrl(): ?string
    {
        if (!$this->resource->relationLoaded('media')) {
            return null;
        }

        $media = $this->media;
        $primary = $media->firstWhere('is_primary', true) ?? $media->first();

        return $primary->url ?? null;
    }

    /**
     * Rentang harga (min-max) dari sell_price seluruh varian.
     */
    protected function priceRange(): ?array
    {
        if (!$this->resource->relationLoaded('variants')) {
            return null;
        }

        $prices = $this->variants
            ->pluck('sell_price')
            ->filter(fn ($price) => $price !== null);

        if ($prices->isEmpty()) {
            return ['min' => 0, 'max' => 0];
        }

        return [
            'min' => (float) $prices->min(),
            'max' => (float) $prices->max(),
        ];
    }
}
