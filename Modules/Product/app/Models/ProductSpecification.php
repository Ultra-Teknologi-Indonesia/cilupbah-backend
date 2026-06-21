<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSpecification extends Model
{
    protected $table = 'product_specifications';

    protected $fillable = [
        'product_id',
        'attribute_id',
        'attribute_option_id',
        'text_value',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function attributeOption(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class);
    }
}
