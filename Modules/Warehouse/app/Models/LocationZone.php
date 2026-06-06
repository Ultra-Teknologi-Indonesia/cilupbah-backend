<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationZone extends Model
{
    protected $fillable = [
        'location_id',
        'zone_code',
        'zone_name',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function bins(): HasMany
    {
        return $this->hasMany(LocationBin::class, 'zone_id');
    }
}
