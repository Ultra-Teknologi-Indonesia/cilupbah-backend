<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAttribute extends Model
{
    protected $table = 'category_attributes';

    protected $fillable = [
        'category_id',
        'attribute_id',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
