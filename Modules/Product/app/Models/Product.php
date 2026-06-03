<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tiktok_product_id',
        'category_id',
        'brand_id',
        'showcase_id',
        'name',
        'sku',
        'description',
        'search_keyword',
        'order_type',
        'indent_days',
        'weight',
        'length',
        'width',
        'height',
        'condition',
        'is_cod_allowed',
        'danger_level',
        'is_draft',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_cod_allowed' => 'boolean',
        'is_draft' => 'boolean',
        'is_active' => 'boolean',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
    ];
}
