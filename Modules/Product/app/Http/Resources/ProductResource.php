<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{

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
            'images' => $this->whenLoaded('media', fn () => $this->media
                ->filter(fn ($m) => $m->variant_id === null && ($m->media_type ?? 'image') === 'image' && $m->url)
                ->sortByDesc('is_primary')
                ->map(fn ($m) => ['url' => $m->url, 'is_primary' => (bool) $m->is_primary])
                ->values()),
            // Media produk-level (image + video) lengkap dengan uuid & urutan,
            // dipakai editor media untuk add/hapus/urutkan/pilih utama.
            'media' => $this->whenLoaded('media', fn () => $this->media
                ->filter(fn ($m) => $m->variant_id === null && $m->url)
                ->sortBy('sort_order')
                ->map(fn ($m) => [
                    'uuid' => $m->media_uuid,
                    'url' => $m->url,
                    'media_type' => $m->media_type ?? 'image',
                    'is_primary' => (bool) $m->is_primary,
                    'sort_order' => (int) $m->sort_order,
                ])->values()),
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
            'product_type' => $this->productType(),
            'total_variants' => $this->totalVariants(),
            'bundle_components' => $this->whenLoaded('bundleItems', fn () => $this->bundleComponents()),
            'bundle_stock' => $this->whenLoaded('bundleItems', fn () => \Modules\Product\Support\BundleStock::derive($this->resource)),
            'is_consignment' => $this->is_consignment,
            'is_stored' => $this->is_stored,
            'is_sold' => $this->is_sold,
            'is_purchased' => $this->is_purchased,
            'order_type' => $this->order_type,
            'is_po' => $this->order_type === 'PREORDER',
            'indent_days' => $this->indent_days,
            'purchase_lead_time' => $this->purchase_lead_time,
            'package_contents' => $this->package_contents,
            'weight' => $this->weight,
            'weight_unit' => $this->weight_unit ?? 'kg',
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'accounts' => [
                'sales' => $this->accountInfo('salesAccount'),
                'sales_return' => $this->accountInfo('salesReturnAccount'),
                'inventory' => $this->accountInfo('inventoryAccount'),
                'cogs' => $this->accountInfo('cogsAccount'),
            ],
            'specifications' => $this->whenLoaded('specifications', fn () => $this->specifications->map(fn ($s) => [
                'attribute_id' => $s->attribute_id,
                'attribute_option_id' => $s->attribute_option_id,
                'value' => $s->text_value ?? ($s->relationLoaded('attributeOption') && $s->attributeOption ? $s->attributeOption->value : null),
            ])->values()),
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
            'variation_types' => $this->whenLoaded('variationTypes', function () {
                return $this->variationTypes
                    ->sortBy('sort_order')
                    ->map(fn ($vt) => [
                        'attribute_id' => $vt->attribute_id,
                        'name' => ($vt->relationLoaded('attribute') && $vt->attribute) ? $vt->attribute->name : null,
                        'sort_order' => $vt->sort_order,
                    ])->values();
            }),
            'variants' => $this->whenLoaded('variants', function () {
                $variantImages = $this->resource->relationLoaded('media')
                    ? $this->media
                        ->filter(fn ($m) => $m->variant_id && ($m->media_type ?? 'image') === 'image' && $m->url)
                        ->groupBy('variant_id')
                    : collect();

                return $this->variants->map(function ($variant) use ($variantImages) {
                    $imgs = $variantImages->get($variant->id);
                    $variantImage = null;
                    if ($imgs && $imgs->isNotEmpty()) {
                        $primary = $imgs->firstWhere('is_primary', true) ?? $imgs->first();
                        $variantImage = $primary->url ?? null;
                    }

                    $data = [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'image' => $variantImage,
                        'options' => $variant->relationLoaded('options')
                            ? $variant->options->map(fn ($o) => [
                                'attribute_id' => $o->attribute_id,
                                'value' => $o->value,
                            ])->values()
                            : [],
                        'barcode' => $variant->barcode,
                        'buy_price' => $variant->buy_price,
                        'sell_price' => $variant->sell_price,
                        'tax_rate' => $variant->tax_rate,
                        'min_stock' => $variant->min_stock,
                        'safe_stock' => $variant->safe_stock,
                        'is_active' => $variant->is_active,
                        'weight' => $variant->weight,
                        'length' => $variant->length,
                        'width' => $variant->width,
                        'height' => $variant->height,
                        'sales_tax' => ($variant->relationLoaded('salesTax') && $variant->salesTax) ? [
                            'id' => $variant->salesTax->id,
                            'name' => $variant->salesTax->name,
                            'rate' => (float) $variant->salesTax->rate,
                        ] : null,
                        'purchase_tax' => ($variant->relationLoaded('purchaseTax') && $variant->purchaseTax) ? [
                            'id' => $variant->purchaseTax->id,
                            'name' => $variant->purchaseTax->name,
                            'rate' => (float) $variant->purchaseTax->rate,
                        ] : null,
                        'unlimited_shops' => $variant->relationLoaded('unlimitedShops')
                            ? $variant->unlimitedShops->map(fn ($s) => [
                                'channel_shop_id' => $s->id,
                                'shop_name' => $s->shop_name,
                            ])->values()
                            : [],
                        'channel_prices' => $variant->relationLoaded('channelMappings') ? $variant->channelMappings->map(function ($map) {
                            return [
                                'channel_shop_id' => $map->relationLoaded('channelMapping') ? $map->channelMapping->channel_shop_id : null,
                                'price' => $map->override_price,
                            ];
                        })->filter(fn($m) => $m['price'] !== null)->values() : [],
                    ];

                    if ($variant->relationLoaded('inventories')) {
                        $data['stock'] = [
                            'on_hand'   => (int) $variant->inventories->sum('on_hand'),
                            'reserved'  => (int) $variant->inventories->sum('reserved'),
                            'on_order'  => (int) $variant->inventories->sum('on_order'),
                            'available' => (int) $variant->inventories->sum('available'),
                        ];
                    }

                    return $data;
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

    protected function totalVariants(): ?int
    {
        if ($this->resource->relationLoaded('variants')) {
            return $this->variants->count();
        }

        return $this->variants_count !== null ? (int) $this->variants_count : null;
    }

    protected function productType(): string
    {
        if ($this->is_bundle) {
            return 'bundle';
        }

        return ($this->totalVariants() ?? 1) > 1 ? 'variant' : 'single';
    }

    protected function bundleComponents(): \Illuminate\Support\Collection
    {
        return $this->bundleItems->map(function ($item) {
            $variant = $item->relationLoaded('component') ? $item->component : null;

            return [
                'component_variant_id' => $item->component_variant_id,
                'qty' => (int) $item->qty,
                'sku' => $variant->sku ?? null,
                'product' => ($variant && $variant->relationLoaded('product') && $variant->product) ? [
                    'id' => $variant->product->id,
                    'name' => $variant->product->name,
                ] : null,
                'variation_values' => ($variant && $variant->relationLoaded('options'))
                    ? $variant->options->map(fn ($o) => [
                        'attribute_id' => $o->attribute_id,
                        'value' => $o->value,
                    ])->values()
                    : [],
                'stock' => ($variant && $variant->relationLoaded('inventories')) ? [
                    'on_hand' => (int) $variant->inventories->sum('on_hand'),
                    'reserved' => (int) $variant->inventories->sum('reserved'),
                    'on_order' => (int) $variant->inventories->sum('on_order'),
                    'available' => (int) $variant->inventories->sum('available'),
                ] : null,
            ];
        })->values();
    }

    protected function primaryImageUrl(): ?string
    {
        if (!$this->resource->relationLoaded('media')) {
            return null;
        }

        $media = $this->media;
        $primary = $media->firstWhere('is_primary', true) ?? $media->first();

        return $primary->url ?? null;
    }

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

    protected function accountInfo(string $relation): ?array
    {
        if (! $this->resource->relationLoaded($relation) || ! $this->{$relation}) {
            return null;
        }

        $account = $this->{$relation};

        return [
            'id' => $account->id,
            'code' => $account->account_code,
            'name' => $account->account_name,
        ];
    }
}
