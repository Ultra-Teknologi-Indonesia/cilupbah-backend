<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'is_active',
        'is_leaf',
        'source',
        'is_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_leaf' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeSystem($query)
    {
        return $query->where('source', 'system');
    }

    public function scopeCustom($query)
    {
        return $query->where('source', 'custom');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attributes')
            ->withPivot('is_required')
            ->withTimestamps();
    }

    public function channelCategories(): BelongsToMany
    {
        return $this->belongsToMany(ChannelCategory::class, 'category_channel_mappings')
            ->withTimestamps();
    }
}
