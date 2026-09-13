<?php

namespace Modules\Product\Observers;

use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Services\ChannelMappingIntegrityService;

final class ProductVariantChannelMappingObserver
{
    public function creating(ProductVariantChannelMapping $mapping): void
    {
        $this->guard($mapping);
    }

    public function updating(ProductVariantChannelMapping $mapping): void
    {
        if (! $mapping->isDirty(['product_channel_mapping_id', 'variant_id'])) {
            return;
        }

        $this->guard($mapping);
    }

    private function guard(ProductVariantChannelMapping $mapping): void
    {
        app(ChannelMappingIntegrityService::class)->assertVariantCanBeLinked(
            (string) $mapping->product_channel_mapping_id,
            (string) $mapping->variant_id,
        );
    }
}
