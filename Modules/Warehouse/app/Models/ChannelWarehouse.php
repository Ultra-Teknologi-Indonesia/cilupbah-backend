<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelWarehouse extends Model
{
    // PK `id` bertipe bigint auto-increment (bukan UUID). channel_id/location_id adalah kolom UUID biasa.

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
