<?php

namespace Modules\Product\Support;

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
