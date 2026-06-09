<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasUuid7;
    protected $fillable = [
        'product_id',
        'sku',
        'sell_price',
        'tax_rate',
        'is_active',
        'is_internal',
        'sequence_item',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_internal' => 'boolean',
        'sell_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'sequence_item' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function channelMappings()
    {
        return $this->hasMany(ProductVariantChannelMapping::class, 'variant_id');
    }

    public function options()
    {
        return $this->hasMany(VariantOption::class, 'variant_id');
    }

    public function inventories()
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class, 'item_id');
    }
}
