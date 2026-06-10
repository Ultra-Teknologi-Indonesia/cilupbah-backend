<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    use HasUuid7;

    protected $fillable = [
        'bundle_product_id',
        'component_variant_id',
        'qty',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
