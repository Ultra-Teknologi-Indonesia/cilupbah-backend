<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChannelAttributeOption extends Model
{
    use HasUuids;

    protected $fillable = [
        'channel_attribute_id',
        'external_id',
        'name',
    ];

    public function channelAttribute(): BelongsTo
    {
        return $this->belongsTo(ChannelAttribute::class);
    }

    public function localAttributeOptions(): BelongsToMany
    {
        return $this->belongsToMany(AttributeOption::class, 'attribute_option_channel_mappings');
    }
}
