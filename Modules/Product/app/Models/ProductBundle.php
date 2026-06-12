<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
