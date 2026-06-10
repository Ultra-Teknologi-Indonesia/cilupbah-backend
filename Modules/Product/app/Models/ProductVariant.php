<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'min_stock',
        'sequence_item',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_internal' => 'boolean',
        'sell_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'sequence_item' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(\Modules\Inventory\Models\Inventory::class, 'item_id');
    }

    public function channelMappings(): HasMany
    {
        return $this->hasMany(ProductVariantChannelMapping::class, 'variant_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(VariantOption::class, 'variant_id');
    }

    public function wholesalePrices(): HasMany
    {
        return $this->hasMany(WholesalePrice::class, 'variant_id');
    }
}
