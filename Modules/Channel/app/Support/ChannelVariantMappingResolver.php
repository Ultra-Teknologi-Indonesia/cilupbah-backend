<?php

namespace Modules\Channel\Support;

use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;

final class ChannelVariantMappingResolver
{
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
