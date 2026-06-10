<?php

namespace Modules\Product\Models;

use App\Traits\HasUuid7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaiseProductDetail extends Model
{
    use HasUuid7;

    protected $fillable = [
        'raise_product_id',
        'product_channel_mapping_id',
        'is_active',
        'is_repeatable',
        'is_success',
        'start_time',
        'end_time',
        'reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_repeatable' => 'boolean',
        'is_success' => 'boolean',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function raiseProduct(): BelongsTo
    {
        return $this->belongsTo(RaiseProduct::class);
    }

    public function channelMapping(): BelongsTo
    {
        return $this->belongsTo(ProductChannelMapping::class, 'product_channel_mapping_id');
    }
}
