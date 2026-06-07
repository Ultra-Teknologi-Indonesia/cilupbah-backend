<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AttributeOption extends Model
{
    protected $fillable = [
        'attribute_id',
        'value',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public function channelAttributeOptions(): BelongsToMany
    {
        return $this->belongsToMany(ChannelAttributeOption::class, 'attribute_option_channel_mappings')
            ->withTimestamps();
    }
}
