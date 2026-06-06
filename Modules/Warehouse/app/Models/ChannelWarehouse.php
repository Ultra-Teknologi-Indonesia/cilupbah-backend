<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Traits\HasUuid7;

class ChannelWarehouse extends Model
{
    use HasUuid7;

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
