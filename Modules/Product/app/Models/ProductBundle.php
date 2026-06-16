<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated B0 consolidation — composition is now stored in the product-keyed
 * `product_bundle_items` table via {@see ProductBundleItem} / Product::bundleItems().
 * Retained read-only until the `product_bundles` table is dropped in a later phase.
 * Do not write to this model.
 */
class ProductBundle extends Model
{
    protected $fillable = [
        'bundle_variant_id',
        'component_variant_id',
        'qty',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function bundleVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'bundle_variant_id');
    }

    public function componentVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
