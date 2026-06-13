<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelWarehouse extends Model
{

    protected $fillable = [
        'location_id',
        'channel_id',
        'store_id',
        'channel_location_id',
        'channel_location_type',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
