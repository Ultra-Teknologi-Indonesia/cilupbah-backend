<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryChannelMapping extends Model
{
    protected $table = 'category_channel_mappings';

    protected $fillable = [
        'category_id',
        'channel_category_id',
        'is_pull_default',
    ];

    protected $casts = [
        'is_pull_default' => 'boolean',
    ];
}
