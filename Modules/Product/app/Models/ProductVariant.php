<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasUuid7;
    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'buy_price',
        'sell_price',
        'tax_rate',
        'sales_tax_id',
        'purchase_tax_id',
        'is_active',
        'is_internal',
        'min_stock',
        'safe_stock',
        'sequence_item',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_internal' => 'boolean',
        'buy_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'min_stock' => 'integer',
        'safe_stock' => 'integer',
        'sequence_item' => 'integer',
    ];

    public function salesTax(): BelongsTo
    {
        return $this->belongsTo(\Modules\Tax\Models\Tax::class, 'sales_tax_id');
    }

    public function purchaseTax(): BelongsTo
    {
        return $this->belongsTo(\Modules\Tax\Models\Tax::class, 'purchase_tax_id');
    }

    public function unlimitedShops(): BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\Channel\Models\ChannelShop::class,
            'variant_unlimited_shops',
            'variant_id',
            'channel_shop_id',
        )->withTimestamps();
    }

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

    /**
     * @deprecated B0 consolidation — use Product::bundleItems() (product-keyed
     * `product_bundle_items`) instead. Kept until `product_bundles` is dropped.
     */
    public function bundleComponents(): HasMany
    {
        return $this->hasMany(ProductBundle::class, 'bundle_variant_id');
    }
}
