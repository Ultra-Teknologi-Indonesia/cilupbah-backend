<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeChannelMapping extends Model
{
    protected $table = 'attribute_channel_mappings';

    protected $fillable = [
        'attribute_id',
        'channel_attribute_id',
    ];

    public function channelAttribute(): BelongsTo
    {
        return $this->belongsTo(ChannelAttribute::class);
    }
}
