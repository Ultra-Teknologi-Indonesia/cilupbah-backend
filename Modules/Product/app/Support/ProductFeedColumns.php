<?php

namespace Modules\Product\Support;

class ProductFeedColumns
{
    public static function list(): array
    {
        return [
            'products.id',
            'products.category_id',
            'products.name',
            'products.sku',
            'products.status',
            'products.order_type',
            'products.is_bundle',
            'products.is_consignment',
            'products.updated_at',
        ];
    }
}
