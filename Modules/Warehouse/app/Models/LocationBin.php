<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationBin extends Model
{
    protected $fillable = [
        'location_id',
        'floor_code',
        'row_code',
        'column_code',
        'bin_code',
        'bin_final_code',
        'max_qty',
        'is_inbound',
    ];

    protected $casts = [
        'is_inbound' => 'boolean',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
