<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAttribute extends Model
{
    use HasUuids;

    protected $fillable = [
        'channel_category_id',
        'external_id',
        'name',
        'is_required',
        'is_multiple',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_multiple' => 'boolean',
    ];

    public function channelCategory(): BelongsTo
    {
        return $this->belongsTo(ChannelCategory::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ChannelAttributeOption::class);
    }

    public function localAttributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_channel_mappings');
    }
}
