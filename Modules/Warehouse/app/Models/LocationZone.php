<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Traits\HasUuid7;

class LocationZone extends Model
{
    use HasUuid7;

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
