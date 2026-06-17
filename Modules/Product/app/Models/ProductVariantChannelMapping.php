<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantChannelMapping extends Model
{
    use HasUuid7;

    protected $fillable = [
        'product_channel_mapping_id',
        'variant_id',
        'external_sku_id',
        'channel_seller_sku',
        'synced_price',
        'synced_stock',
        'override_price',
    ];

    protected $casts = [
        'synced_price' => 'decimal:2',
        'synced_stock' => 'integer',
        'override_price' => 'decimal:2',
    ];

    public function channelMapping(): BelongsTo
    {
        return $this->belongsTo(ProductChannelMapping::class, 'product_channel_mapping_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
