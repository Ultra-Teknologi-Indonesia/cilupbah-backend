<?php

namespace Modules\Product\Support;

/**
 * Eager-load set shared by every feed rendered through MasterItemResource
 * (master, review, archive, downloaded, catalog).
 *
 * Two deliberate choices here:
 *
 * - Every relation selects only the columns the resource actually reads. A page
 *   of 500 products fans out into thousands of variants, options, media rows and
 *   channel mappings; hydrating full rows for all of them is the bulk of the
 *   response time once the join keys are indexed.
 * - `variants.channelMappings` stops at the pivot. The resource only needs a
 *   shop name per variant mapping, and the parent product_channel_mappings rows
 *   (with their shop and channel) are already loaded at product level — walking
 *   `channelMapping.channelShop.channel` again would re-hydrate the same rows
 *   once per variant mapping.
 */
class ProductFeedRelations
{
    public static function base(): array
    {
        return [
            'category:id,name',
            'variationTypes:id,product_id,attribute_id,sort_order',
            'variationTypes.attribute:id,name',
            'variants:id,product_id,sku,barcode,sell_price,tax_rate,is_internal,sequence_item,deleted_at',
            'variants.options:id,variant_id,attribute_id,value',
            'variants.options.attribute:id,name',
            'variants.channelMappings:id,variant_id,product_channel_mapping_id',
            'media:id,product_id,variant_id,url,is_primary,sort_order',
            'channelMappings:id,product_id,channel_shop_id,external_product_id,channel_url,sync_status,error_message',
            'channelMappings.channelShop:id,channel_id,shop_id,shop_name',
            'channelMappings.channelShop.channel:id,code,name',
        ];
    }

    public static function withArchivedBy(): array
    {
        return array_merge(self::base(), ['archivedBy:id,email']);
    }

    public static function withInventories(): array
    {
        return array_merge(self::base(), ['variants.inventories:id,item_id,on_hand,on_order']);
    }
}
