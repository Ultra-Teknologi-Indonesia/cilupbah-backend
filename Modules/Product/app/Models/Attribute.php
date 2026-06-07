<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Attribute extends Model
{
    protected $fillable = [
        'name',
        'type', // 'sales' or 'spec'
    ];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attributes')
            ->withPivot('is_required')
            ->withTimestamps();
    }

    public function channelAttributes(): BelongsToMany
    {
        return $this->belongsToMany(ChannelAttribute::class, 'attribute_channel_mappings')
            ->withTimestamps();
    }
}
