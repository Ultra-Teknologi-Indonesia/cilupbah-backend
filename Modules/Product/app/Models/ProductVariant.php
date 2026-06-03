<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'sell_price',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sell_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
