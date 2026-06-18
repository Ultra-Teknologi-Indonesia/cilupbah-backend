<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Channel\Models\Channel;

class ChannelCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'channel_id',
        'external_id',
        'parent_external_id',
        'name',
        'is_leaf',
        'rules',
    ];

    protected $casts = [
        'is_leaf' => 'boolean',
        'rules' => 'array',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function localCategories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_channel_mappings');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChannelCategory::class, 'parent_external_id', 'external_id')
                    ->where('channel_id', $this->channel_id);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChannelCategory::class, 'external_id', 'parent_external_id')
                    ->where('channel_id', $this->channel_id);
    }
}
