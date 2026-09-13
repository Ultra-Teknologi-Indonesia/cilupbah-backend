<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Collection;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;

final class ChannelVariantMappingResolver
{
    public static function listing(
        Product $product,
        ChannelShop $shop,
        string $externalProductId,
        ?ProductChannelMapping $listing = null,
    ): ?ProductChannelMapping {
        if ($listing !== null) {
            $isExpectedListing = (string) $listing->product_id === (string) $product->id
                && (string) $listing->channel_shop_id === (string) $shop->id
                && (string) $listing->external_product_id === $externalProductId;

            if (! $isExpectedListing) {
                return null;
            }

            $listing->loadMissing('variantMappings.variant');

            return $listing;
        }

        return ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', $externalProductId)
            ->with('variantMappings.variant')
            ->first();
    }

    public static function enabledForListing(ProductChannelMapping $listing): Collection
    {
        $listing->loadMissing('variantMappings.variant');

        return $listing->variantMappings
            ->filter(static fn (ProductVariantChannelMapping $mapping): bool => (bool) $mapping->sync_enabled
                && $mapping->variant !== null
                && (string) $mapping->variant->product_id === (string) $listing->product_id
            )
            ->sortBy('id')
            ->values();
    }

    public static function stockPayloadError(
        Collection $mappings,
        string $identifierAttribute,
        string $identifierLabel,
        bool $allowOneBlankIdentifier = false,
    ): ?string {
        if ($mappings->isEmpty()) {
            return 'Tidak ada varian aktif yang terhubung pada listing ini.';
        }

        if ($mappings->pluck('variant_id')->duplicates()->isNotEmpty()) {
            return 'Satu varian master terpetakan lebih dari sekali pada listing ini.';
        }

        $blankIdentifiers = $mappings->filter(
            static fn (ProductVariantChannelMapping $mapping): bool => blank($mapping->{$identifierAttribute}),
        );

        if ($blankIdentifiers->isNotEmpty()
            && (! $allowOneBlankIdentifier || $mappings->count() !== 1)) {
            return "{$identifierLabel} belum lengkap untuk seluruh varian listing ini.";
        }

        $identifiers = $mappings
            ->reject(static fn (ProductVariantChannelMapping $mapping): bool => blank($mapping->{$identifierAttribute}))
            ->map(static fn (ProductVariantChannelMapping $mapping): string => (string) $mapping->{$identifierAttribute});

        if ($identifiers->duplicates()->isNotEmpty()) {
            return "{$identifierLabel} terduplikasi pada listing ini.";
        }

        return null;
    }

    public static function forShop(
        ProductVariant $variant,
        ChannelShop $shop,
    ): ?ProductVariantChannelMapping {
        if ($variant->relationLoaded('channelMappings')) {
            $mapping = $variant->channelMappings->first(
                static function (ProductVariantChannelMapping $mapping) use ($shop): bool {
                    if (! $mapping->relationLoaded('channelMapping') || ! $mapping->channelMapping) {
                        return false;
                    }

                    return (string) $mapping->channelMapping->channel_shop_id === (string) $shop->id;
                },
            );

            if ($mapping) {
                return $mapping;
            }
        }

        return $variant->channelMappings()
            ->whereHas('channelMapping', function ($query) use ($shop): void {
                $query->where('channel_shop_id', $shop->id);
            })
            ->first();
    }
}
